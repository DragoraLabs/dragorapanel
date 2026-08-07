<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MarketplacePlugin;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use ZipArchive;

class MarketplaceController extends Controller
{
    use Concerns\MarketplaceAuth;

    /** Root path of the storefront: / in store mode, /store in panel mode. */
    private function root(): string
    {
        return config('app.store_mode') ? '' : '/store';
    }

    private function dashUrl(): string
    {
        return $this->root() . '/dashboard';
    }

    private function loginUrl(): string
    {
        return $this->root() . '/login';
    }
    // ── Auth (cookie-based for this public website) ──

    private function authResponse($response, User $user): \Illuminate\Http\RedirectResponse
    {
        $token = bin2hex(random_bytes(32));
        $key = bin2hex(random_bytes(16));
        Session::create([
            'user_id' => $user->id,
            'token' => $token,
            'session_key' => $key,
            'expires_at' => now()->addDays(30),
        ]);
        return $response->withCookie(cookie('mp_token', $token, 43200, '/', null, false, false))
                        ->withCookie(cookie('mp_key', $key, 43200, '/', null, false, false));
    }

    // ── Pages ──

    public function index(Request $request)
    {
        $qs = $request->query('q', '');
        $plugins = MarketplacePlugin::with('author')
            ->where('status', 'approved')
            ->when($qs !== '', fn ($q) => $q->where(function ($w) use ($qs) {
                $w->where('name', 'like', '%' . $qs . '%')->orWhere('description', 'like', '%' . $qs . '%');
            }))
            ->orderByDesc('downloads')
            ->paginate(12);
        return view('marketplace.index', [
            'user' => $this->currentUser($request),
            'plugins' => $plugins,
            'q' => $qs,
        ]);
    }

    public function show(Request $request, int $id)
    {
        $plugin = MarketplacePlugin::where('id', $id)->where('status', 'approved')->with('author')->firstOrFail();
        return view('marketplace.show', ['user' => $this->currentUser($request), 'plugin' => $plugin]);
    }

    public function registerForm(Request $request) { return view('marketplace.register', ['user' => $this->currentUser($request)]); }
    public function loginForm(Request $request) { return view('marketplace.login', ['user' => $this->currentUser($request)]); }

    public function register(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
        ]);
        $user = User::create([
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
        ]);
        return $this->authResponse(redirect($this->dashUrl())->with('ok', 'Account created. Welcome!'), $user);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required']);
        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['email' => 'Invalid email or password.']);
        }

        // Two-factor gate: hold the pending login in the session, verify a TOTP code next.
        if ($user->two_fa_enabled && $user->two_fa_secret) {
            session()->put('mp_2fa_pending', [
                'user_id' => $user->id,
                'password_hash' => $user->password,
            ]);
            return redirect($this->root() . '/login/2fa');
        }

        return $this->authResponse(redirect($this->dashUrl())->with('ok', 'Signed in.'), $user);
    }

    public function twoFaForm(Request $request)
    {
        $pending = session('mp_2fa_pending');
        if (!$pending) return redirect($this->loginUrl());
        $user = User::find($pending['user_id'] ?? 0);
        return view('marketplace.2fa', ['user' => $user, 'pending' => true]);
    }

    public function twoFaVerify(Request $request)
    {
        $pending = session('mp_2fa_pending');
        if (!$pending) return redirect($this->loginUrl());
        $user = User::find($pending['user_id'] ?? 0);
        if (!$user || !$user->two_fa_secret) return redirect($this->loginUrl());

        $data = $request->validate(['code' => 'required|string|max:10']);
        $totp = \OTPHP\TOTP::createFromSecret($user->two_fa_secret);
        if (!$totp->verify(trim($data['code']), null, 2)) {
            return back()->withErrors(['code' => 'Invalid code. Try again.']);
        }

        session()->forget('mp_2fa_pending');
        return $this->authResponse(redirect($this->dashUrl())->with('ok', 'Signed in.'), $user);
    }

    public function logout(Request $request)
    {
        $token = $request->cookie('mp_token');
        if ($token) Session::where('token', $token)->delete();
        session()->forget('mp_2fa_pending');
        return redirect($this->root() ?: '/')->withCookie(cookie()->forget('mp_token'))->withCookie(cookie()->forget('mp_key'));
    }

    // ── Dashboard (my plugins + upload) ──

    public function dashboard(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->loginUrl());
        $mine = MarketplacePlugin::where('user_id', $user->id)->orderByDesc('updated_at')->get();
        return view('marketplace.dashboard', ['user' => $user, 'mine' => $mine]);
    }

    public function upload(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->loginUrl());

        $data = $request->validate([
            'name' => 'required|string|max:128',
            'version' => 'required|string|max:32',
            'description' => 'nullable|string|max:5000',
            'license' => 'nullable|string|max:64',
            'icon' => 'nullable|string|max:64',
            'logo' => 'nullable|mimes:png,jpg,jpeg,svg,webp|max:2048',
            'zip' => 'required|mimes:zip|max:20480',
        ]);

        $file = $request->file('zip');
        $zip = new ZipArchive;
        if ($zip->open($file->getPathname()) !== true) {
            return back()->withErrors(['zip' => 'Cannot open zip file.']);
        }
        $manifestJson = $zip->getFromName('plugin.json');
        if ($manifestJson === false) {
            $zip->close();
            return back()->withErrors(['zip' => 'Zip must contain a plugin.json manifest in its root.']);
        }
        $manifest = json_decode($manifestJson, true);
        $zip->close();
        if (!$manifest || empty($manifest['unique_id']) || empty($manifest['name'])) {
            return back()->withErrors(['zip' => 'Invalid plugin.json: unique_id and name are required.']);
        }
        $uniqueId = $manifest['unique_id'];

        $existing = MarketplacePlugin::where('unique_id', $uniqueId)->first();
        if ($existing) {
            return back()->withErrors(['zip' => 'A plugin with id "' . $uniqueId . '" is already on the store (status: ' . $existing->status . ').']);
        }

        $dir = storage_path('app/marketplace/' . $uniqueId);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $zipName = $uniqueId . '.zip';
        $file->move($dir, $zipName);

        // Optional logo upload (icon image), else keep fontawesome icon.
        $icon = $data['icon'] ?? $manifest['icon'] ?? 'fa-plug';
        if ($request->hasFile('logo')) {
            $ext = $request->file('logo')->getClientOriginalExtension();
            $request->file('logo')->move($dir, 'icon.' . $ext);
            // iconUrl() will glob assetDir()/icon.* and serve it via store.raw.
        }

        MarketplacePlugin::create([
            'user_id' => $user->id,
            'unique_id' => $uniqueId,
            'name' => $data['name'] ?? $manifest['name'] ?? '',
            'version' => $data['version'] ?? $manifest['version'] ?? '1.0.0',
            'description' => $data['description'] ?? $manifest['description'] ?? '',
            'license' => $data['license'] ?? $manifest['license'] ?? '',
            'icon' => $icon,
            'zip_file' => $zipName,
            'hooks' => isset($manifest['hooks']) ? $manifest['hooks'] : null,
            'status' => 'pending',
        ]);

        ActivityLog::create([
            'action' => 'marketplace:submit',
            'user_id' => $user->id,
            'metadata' => json_encode(['plugin' => $uniqueId, 'name' => $data['name']]),
            'ip_address' => $request->ip(),
        ]);

        return redirect($this->dashUrl())->with('ok', 'Plugin submitted! An admin will review it before it becomes public.');
    }

    // stale file / uninstall
    public function destroy(Request $request, int $id)
    {
        $user = $this->currentUser($request);
        if (!$user) return redirect($this->loginUrl());
        $plugin = MarketplacePlugin::findOrFail($id);
        if ($plugin->user_id !== $user->id) abort(403);
        $this->rmdirRecursive($plugin->assetDir());
        $plugin->delete();
        return redirect($this->dashUrl())->with('ok', 'Submission removed.');
    }

    // ── Admin review queue ──

    public function reviewIndex(Request $request)
    {
        $user = $this->currentUser($request);
        if (!$user || !$user->isAdmin()) abort(403);
        $status = $request->query('status', 'pending');
        $plugins = MarketplacePlugin::with('author')
            ->when(in_array($status, ['pending', 'approved', 'rejected']), fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')->get();
        return view('marketplace.review', ['user' => $user, 'plugins' => $plugins, 'status' => $status]);
    }

    public function reviewSet(Request $request, int $id)
    {
        $user = $this->currentUser($request);
        if (!$user || !$user->isAdmin()) abort(403);
        $data = $request->validate([
            'action' => 'required|in:approve,reject',
            'reason' => 'nullable|string|max:255',
        ]);
        $plugin = MarketplacePlugin::findOrFail($id);
        $plugin->update([
            'status' => $data['action'] === 'approve' ? 'approved' : 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'reject_reason' => $data['action'] === 'reject' ? ($data['reason'] ?? 'Not approved') : null,
        ]);
        ActivityLog::create([
            'action' => 'marketplace:' . $data['action'],
            'user_id' => $user->id,
            'metadata' => json_encode(['plugin' => $plugin->unique_id, 'id' => $id]),
            'ip_address' => $request->ip(),
        ]);
        return redirect($this->root() . '/admin?status=pending')->with('ok', 'Plugin ' . $data['action'] . 'd.');
    }

    // Serve a stored asset (icon etc.) — public, safe path traversal guard.
    public function raw(int $id, string $path = ''): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $plugin = MarketplacePlugin::findOrFail($id);
        $full = realpath($plugin->assetDir() . '/' . ltrim($path, '/'));
        $base = realpath($plugin->assetDir());
        if (!$base || !$full || !str_starts_with($full, $base)) abort(404);
        if (!is_file($full)) abort(404);
        return response()->file($full);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        @rmdir($dir);
    }
}