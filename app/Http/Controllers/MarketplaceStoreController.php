<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Plugin;
use App\Models\Setting;
use App\Services\ApiAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Panel-facing "Plugin Store" client. The actual marketplace lives on a
 * separate standalone site (plugins.dragoralabs.qzz.io). This controller
 * proxies its public JSON API: list approved plugins and install them into
 * this panel by downloading the zip over HTTP.
 */
class MarketplaceStoreController extends Controller
{
    private function user(Request $request): ?\App\Models\User
    {
        return ApiAuth::user($request);
    }

    private function requireAdmin(Request $request): ?JsonResponse
    {
        $user = $this->user($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        if (!$user->isAdmin()) return response()->json(['success' => false, 'error' => 'Forbidden.'], 403);
        return null;
    }

    private function storeUrl(): string
    {
        return rtrim((string) env('STORE_API_URL', 'https://plugins.dragoralabs.qzz.io'), '/');
    }

    /** Shared firewall key the store requires for file downloads. */
    private function storeClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(30)->withHeaders([
            'X-Store-Key' => (string) env('STORE_API_KEY', ''),
        ]);
    }

    /** Browse the remote store: approved plugin list with "installed" flags. */
    public function index(Request $request): JsonResponse
    {
        try {
            $r = $this->storeClient()->get($this->storeUrl() . '/api/plugins', [
                'q' => trim((string) $request->query('q', '')),
            ]);
        } catch (ConnectionException $e) {
            return response()->json(['success' => false, 'error' => 'Store unreachable (' . $this->storeUrl() . '): ' . $e->getMessage()], 502);
        }

        if (!$r->ok() || !($r->json('success') ?? false)) {
            return response()->json(['success' => false, 'error' => 'Store returned an error (' . $r->status() . ').'], 502);
        }

        $plugins = collect($r->json('plugins') ?? [])->map(function ($p) {
            return [
                'id' => $p['id'] ?? null,
                'name' => $p['name'] ?? '',
                'unique_id' => $p['unique_id'] ?? '',
                'version' => $p['version'] ?? '',
                'description' => $p['description'] ?? '',
                'license' => $p['license'] ?? '',
                'author' => $p['author'] ?? '',
                'icon' => $p['icon_url'] ?? ($p['icon'] ?? ''),
                'downloads' => $p['downloads'] ?? 0,
                'size' => $p['size'] ?? 0,
                'installed' => Plugin::where('unique_id', $p['unique_id'] ?? '__none__')->exists(),
            ];
        });

        return response()->json(['success' => true, 'plugins' => $plugins]);
    }

    /** Install an approved plugin from the remote store into this panel. */
    public function install(Request $request, int $id): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;

        // Direct mode: the store pushes the plugin zip (multipart) so this panel
        // never has to call back to the store — avoids deadlocking two
        // single-threaded `php artisan serve` workers.
        if ($request->hasFile('zip')) {
            $uniqueId = trim((string) $request->input('unique_id', ''));
            if ($uniqueId === '' || Plugin::where('unique_id', $uniqueId)->exists()) {
                return response()->json(['success' => false, 'error' => 'Plugin "' . $uniqueId . '" is already installed.'], 409);
            }

            $file = $request->file('zip');
            $za = new \ZipArchive;
            if ($za->open($file->getPathname()) !== true) {
                return response()->json(['success' => false, 'error' => 'Cannot open uploaded archive.'], 500);
            }
            $manifest = json_decode($za->getFromName('plugin.json') ?: '', true) ?: [];
            $extractPath = storage_path('app/plugins/' . $uniqueId);
            $za->extractTo($extractPath);
            $za->close();

            Plugin::create([
                'unique_id' => $uniqueId,
                'name' => (string) ($request->input('name', '') ?: ($manifest['name'] ?? $uniqueId)),
                'version' => (string) ($request->input('version', '') ?: '1.0.0'),
                'description' => (string) ($request->input('description', '') ?: ($manifest['description'] ?? '')),
                'author' => $manifest['author'] ?? '',
                'license' => (string) ($request->input('license', '') ?: ($manifest['license'] ?? '')),
                'icon' => $manifest['icon'] ?? 'fa-plug',
                'hooks' => $manifest['hooks'] ?? [],
                'enabled' => true,
            ]);

            $admin = $this->user($request);
            if ($admin) {
                ActivityLog::create([
                    'action' => 'plugin:install:store',
                    'user_id' => $admin->id,
                    'metadata' => json_encode(['plugin' => $uniqueId, 'store_id' => $id]),
                    'ip_address' => $request->ip(),
                ]);
            }

            return response()->json(['success' => true, 'plugin' => $uniqueId]);
        }

        try {
            $meta = $this->storeClient()->get($this->storeUrl() . '/api/plugins/' . $id);
        } catch (ConnectionException $e) {
            return response()->json(['success' => false, 'error' => 'Store unreachable.'], 502);
        }

        if (!$meta->ok() || !($meta->json('success') ?? false)) {
            return response()->json(['success' => false, 'error' => 'Plugin not found on the store.'], 404);
        }
        $info = $meta->json('plugin') ?? [];
        $uniqueId = $info['unique_id'] ?? '';
        if ($uniqueId === '' || Plugin::where('unique_id', $uniqueId)->exists()) {
            return response()->json(['success' => false, 'error' => 'Plugin "' . $uniqueId . '" is already installed.'], 409);
        }

        // Download the zip to a temp file.
        $tmp = tempnam(sys_get_temp_dir(), 'mp_');
        try {
            $response = $this->storeClient()->withOptions(['sink' => $tmp])->get($this->storeUrl() . '/api/plugins/' . $id . '/zip');
        } catch (ConnectionException $e) {
            @unlink($tmp);
            return response()->json(['success' => false, 'error' => 'Store unreachable while downloading.'], 502);
        }
        if (!$response->ok() || filesize($tmp) < 10) {
            @unlink($tmp);
            return response()->json(['success' => false, 'error' => 'Failed to download plugin archive.'], 502);
        }

        $za = new \ZipArchive;
        if ($za->open($tmp) !== true) {
            @unlink($tmp);
            return response()->json(['success' => false, 'error' => 'Cannot open downloaded archive.'], 500);
        }
        $manifest = json_decode($za->getFromName('plugin.json') ?: '', true) ?: [];
        $extractPath = storage_path('app/plugins/' . $uniqueId);
        $za->extractTo($extractPath);
        $za->close();
        @unlink($tmp);

        Plugin::create([
            'unique_id' => $uniqueId,
            'name' => $info['name'] ?? $uniqueId,
            'version' => $info['version'] ?? '1.0.0',
            'description' => $info['description'] ?? '',
            'author' => $manifest['author'] ?? '',
            'license' => $info['license'] ?? '',
            'icon' => $manifest['icon'] ?? 'fa-plug',
            'hooks' => $manifest['hooks'] ?? [],
            'enabled' => true,
        ]);

        $admin = $this->user($request);
        if ($admin) {
            ActivityLog::create([
                'action' => 'plugin:install:store',
                'user_id' => $admin->id,
                'metadata' => json_encode(['plugin' => $uniqueId, 'store_id' => $id]),
                'ip_address' => $request->ip(),
            ]);
        }

        return response()->json(['success' => true, 'plugin' => $uniqueId]);
    }

    /**
     * Lightweight connection check used by the marketplace site to verify a
     * panel URL + API key pair before allowing remote install.
     */
    public function ping(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }
        return response()->json([
            'success' => true,
            'name' => $user->first_name . ' ' . $user->last_name,
            'role' => $user->role,
            'panel' => $user->isAdmin() ? 'admin' : 'user',
        ]);
    }

    /** Generate a one-stop connection link that opens the store and links this panel to an account. */
    public function connectionLink(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (!$user || !$user->isAdmin()) {
            return response()->json(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        // Stable instance id for this panel (generated once, kept in settings).
        $panelId = (string) Setting::get('panel:instance_id');
        if ($panelId === '') {
            $panelId = (string) \Illuminate\Support\Str::uuid();
            Setting::set('panel:instance_id', $panelId);
        }

        $issued = \App\Services\ApiAuth::issue($user, 'Plugin Store connection link', true);

        $storeUrl = rtrim((string) env('STORE_API_URL', 'https://plugins.dragoralabs.qzz.io'), '/');
        $panelUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        // base64url keeps the URL path-safe (no raw / : ? characters).
        $panelUrlCode = rtrim(strtr(base64_encode($panelUrl), '+/', '-_'), '=');
        $link = $storeUrl . '/panel/save/' . $panelId . '/' . $panelUrlCode . '/' . $issued['token'];

        return response()->json([
            'success' => true,
            'link' => $link,
            'note' => 'Open this link while signed in to the plugin store to link this panel to your account.',
        ]);
    }
}