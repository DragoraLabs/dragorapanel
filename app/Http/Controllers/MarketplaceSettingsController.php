<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OTPHP\TOTP;

/**
 * Account settings for the store site: profile, password, 2FA, API keys,
 * appearance (theme color) and organizations. All actions require the
 * marketplace cookie session (currentUser).
 */
class MarketplaceSettingsController extends Controller
{
    use Concerns\MarketplaceAuth;

    private function root(): string
    {
        return config('app.store_mode') ? '' : '/store';
    }

    private function settingsUrl(string $tab = 'profile'): string
    {
        return $this->root() . '/settings?tab=' . $tab;
    }

    /** Settings page with tab support (profile | security | appearance | organization). */
    public function settings(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $tab = $request->query('tab', 'profile');
        if (!in_array($tab, ['profile', 'security', 'appearance', 'organization'])) $tab = 'profile';

        $organizations = $user->organizations()->with('owner:id,username,first_name,last_name,email,avatar')->get();
        $myOrgs = $organizations->pluck('id');
        $membersByOrg = [];
        if ($myOrgs->count()) {
            foreach (Organization::with('members:id,username,first_name,last_name,email,avatar')->whereIn('id', $myOrgs)->get() as $org) {
                $membersByOrg[$org->id] = $org->members;
            }
        }

        return view('marketplace.settings', [
            'user' => $user,
            'tab' => $tab,
            'organizations' => $organizations,
            'membersByOrg' => $membersByOrg,
            'totp' => null,
        ]);
    }

    // ── Profile ──

    public function updateProfile(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $data = $request->validate([
            'username' => 'nullable|string|max:64|regex:/^[a-zA-Z0-9_.-]+$/|unique:users,username,' . $user->id,
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'bio' => 'nullable|string|max:500',
        ]);

        $user->update($data);

        ActivityLog::create([
            'action' => 'settings:profile',
            'user_id' => $user->id,
            'metadata' => json_encode(['email' => $data['email']]),
            'ip_address' => $request->ip(),
        ]);

        return redirect($this->settingsUrl('profile'))->with('ok', 'Profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $data = $request->validate([
            'current_password' => 'required',
            'password' => 'required|min:8|confirmed',
        ]);
        if (!Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }
        $user->update(['password' => Hash::make($data['password'])]);

        return redirect($this->settingsUrl('profile'))->with('ok', 'Password changed.');
    }

    public function updateAvatar(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $data = $request->validate(['avatar' => 'required|mimes:png,jpg,jpeg,webp|max:2048']);
        $dir = storage_path('app/avatars/' . $user->id);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        // Normalize extension and remove any previous avatar files so only one exists.
        $ext = strtolower($data['avatar']->getClientOriginalExtension());
        if ($ext === 'jpeg') $ext = 'jpg';
        foreach (glob($dir . '/avatar.*') ?: [] as $old) @unlink($old);
        $data['avatar']->move($dir, 'avatar.' . $ext);
        $user->update(['avatar' => '/avatar/' . $user->id]);

        return redirect($this->settingsUrl('profile'))->with('ok', 'Avatar updated.');
    }

    /** Serve a user avatar image (public). */
    public function avatar(int $id)
    {
        $user = User::findOrFail($id);
        if (!$user->avatar) abort(404);
        $dir = storage_path('app/avatars/' . $user->id);
        $glob = glob($dir . '/avatar.*');
        if (!$glob) abort(404);
        $ext = pathinfo($glob[0], PATHINFO_EXTENSION);
        $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
        $response = response()->file($glob[0], ['Content-Type' => $mimes[$ext] ?? 'application/octet-stream']);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }

    // ── Security: 2FA + API keys ──

    public function enable2fa(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        if ($user->two_fa_enabled) {
            return redirect($this->settingsUrl('security'))->withErrors(['2fa' => 'Two-factor authentication is already enabled.']);
        }

        // New secret every time the setup page is requested.
        $totp = TOTP::create();
        $secret = $totp->getSecret();
        $user->update(['two_fa_secret' => $secret, 'two_fa_enabled' => false]);

        $totp->setLabel($user->displayName());
        $totp->setIssuer('DragoraLabs Plugins');
        $provisioningUri = $totp->getProvisioningUri();

        // Render QR as SVG data URI.
        $renderer = new ImageRenderer(new RendererStyle(220, 4), new SvgImageBackEnd());
        $qr = 'data:image/svg+xml;base64,' . base64_encode($renderer->render(\BaconQrCode\Encoder\Encoder::encode($provisioningUri, \BaconQrCode\Common\ErrorCorrectionLevel::L())));

        return redirect($this->settingsUrl('security'))->with('pending2fa', ['secret' => $secret, 'qr' => $qr]);
    }

    public function confirm2fa(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');
        if (!$user->two_fa_secret) return redirect($this->settingsUrl('security'))->withErrors(['2fa' => 'Start 2FA setup first.']);

        $data = $request->validate(['code' => 'required|string|max:10']);
        $totp = TOTP::createFromSecret($user->two_fa_secret);
        if (!$totp->verify(trim($data['code']), null, 2)) {
            return back()->withErrors(['code' => 'Invalid code. Check the code in your authenticator app.']);
        }
        $user->update(['two_fa_enabled' => true]);

        return redirect($this->settingsUrl('security'))->with('ok', 'Two-factor authentication enabled.');
    }

    public function disable2fa(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $data = $request->validate([
            'code' => 'required|string|max:10',
            'password' => 'required',
        ]);
        if (!Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Password is incorrect.']);
        }
        if ($user->two_fa_secret) {
            $totp = TOTP::createFromSecret($user->two_fa_secret);
            if (!$totp->verify(trim($data['code']), null, 2)) {
                return back()->withErrors(['code' => 'Invalid authenticator code.']);
            }
        }
        $user->update(['two_fa_secret' => null, 'two_fa_enabled' => false]);

        return redirect($this->settingsUrl('security'))->with('ok', 'Two-factor authentication disabled.');
    }

    // ── API keys (Dragora API: dlpu_ user / dpla_ admin) ──

    public function createApiKey(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'scope' => 'required|in:user,admin',
        ]);
        if ($data['scope'] === 'admin' && !$user->isAdmin()) {
            return back()->withErrors(['scope' => 'Admin scope requires an admin account.']);
        }

        $plain = Str::random(40);
        $prefix = $data['scope'] === 'admin' ? 'dpla_' : 'dlpu_';
        ApiToken::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'scope' => $data['scope'],
            'token_hash' => hash('sha256', $prefix . $plain),
            'expires_at' => null,
        ]);

        return redirect($this->settingsUrl('security'))
            ->with('ok', 'API key created.')
            ->with('newApiKey', $prefix . $plain);
    }

    public function revokeApiKey(Request $request, int $id)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');
        $key = ApiToken::where('id', $id)->where('user_id', $user->id)->firstOrFail();
        $key->delete();
        return redirect($this->settingsUrl('security'))->with('ok', 'API key revoked.');
    }

    // ── Appearance ──

    public function updateTheme(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $data = $request->validate([
            'theme' => 'required|string|max:32|regex:/^[a-z0-9-]+$/',
        ]);
        $user->update(['theme' => $data['theme']]);

        return redirect($this->settingsUrl('appearance'))->with('ok', 'Theme updated.');
    }

    // ── Organizations ──

    public function createOrganization(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
        ]);

        $slug = Str::slug($data['name']) ?: 'org-' . Str::lower(Str::random(6));
        $slug = $slug . '-' . Str::lower(Str::random(4));
        $org = Organization::create([
            'name' => $data['name'],
            'slug' => $slug,
            'invite_code' => Str::upper(Str::random(8)),
            'description' => $data['description'],
            'owner_id' => $user->id,
        ]);
        $org->members()->attach($user->id, ['role' => 'owner']);

        return redirect($this->settingsUrl('organization'))->with('ok', 'Organization "' . $org->name . '" created.');
    }

    public function joinOrganization(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');

        $data = $request->validate(['invite_code' => 'required|string|max:12']);
        $org = Organization::where('invite_code', Str::upper(trim($data['invite_code'])))->first();
        if (!$org) return back()->withErrors(['invite_code' => 'Invalid invite code.']);
        if ($org->members()->where('user_id', $user->id)->exists()) {
            return back()->withErrors(['invite_code' => 'You are already a member of this organization.']);
        }
        $org->members()->attach($user->id, ['role' => 'member']);

        return redirect($this->settingsUrl('organization'))->with('ok', 'Joined "' . $org->name . '".');
    }

    public function leaveOrganization(Request $request, int $id)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');
        $org = Organization::findOrFail($id);

        if ($org->owner_id === $user->id) {
            return back()->withErrors(['org' => 'The owner cannot leave. Delete the organization instead.']);
        }
        $org->members()->detach($user->id);

        return redirect($this->settingsUrl('organization'))->with('ok', 'Left the organization.');
    }

    public function deleteOrganization(Request $request, int $id)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');
        $org = Organization::findOrFail($id);
        if ($org->owner_id !== $user->id) abort(403);
        $org->delete();

        return redirect($this->settingsUrl('organization'))->with('ok', 'Organization deleted.');
    }

    public function removeMember(Request $request, int $orgId, int $memberId)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');
        $org = Organization::findOrFail($orgId);
        if ($org->owner_id !== $user->id) abort(403);
        if ($memberId === $org->owner_id) return back()->withErrors(['org' => 'Cannot remove the owner.']);
        $org->members()->detach($memberId);

        return redirect($this->settingsUrl('organization'))->with('ok', 'Member removed.');
    }

    public function inviteMember(Request $request, int $orgId)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->root() . '/login');
        $org = Organization::findOrFail($orgId);
        if ($org->owner_id !== $user->id) abort(403);

        $data = $request->validate(['email' => 'required|email']);
        $target = User::where('email', $data['email'])->first();
        if (!$target) return back()->withErrors(['email' => 'No account with that email exists on the store yet.']);
        if ($org->members()->where('user_id', $target->id)->exists()) {
            return back()->withErrors(['email' => 'That user is already a member.']);
        }
        $org->members()->attach($target->id, ['role' => 'member']);

        return redirect($this->settingsUrl('organization'))->with('ok', $target->displayName() . ' added to ' . $org->name . '.');
    }
}
