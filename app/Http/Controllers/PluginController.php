<?php

namespace App\Http\Controllers;

use App\Models\Plugin;
use App\Models\ActivityLog;
use App\Models\Session;
use App\Models\Setting;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class PluginController extends Controller
{
    private function getUser(Request $request): ?\App\Models\User
    {
        return ApiAuth::user($request);
    }

    private function requireAdmin(Request $request): ?JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        if (!$user->isAdmin()) return response()->json(['success' => false, 'error' => 'Forbidden.'], 403);
        return null;
    }

    public function index(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $plugins = Plugin::orderBy('name')->get()->map(function ($p) {
            $manifestPath = $p->storagePath() . '/plugin.json';
            $hooks = [];
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                $hooks = $manifest['hooks'] ?? [];
            } else {
                $hooks = $p->hooks ?? [];
            }
            return [
                'id' => $p->id,
                'unique_id' => $p->unique_id,
                'name' => $p->name,
                'version' => $p->version,
                'description' => $p->description,
                'author' => $p->author,
                'license' => $p->license,
                'icon' => $p->icon,
                'enabled' => $p->enabled,
                'hooks' => $hooks,
                'installed_at' => $p->created_at,
            ];
        });
        return response()->json(['success' => true, 'plugins' => $plugins]);
    }

    /**
     * Lightweight signature of the installed plugin set. The frontend polls this
     * and reloads every page when it changes (e.g. the store installed a plugin
     * into this panel while the panel was open).
     */
    public function signature(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $sig = Plugin::orderBy('unique_id')->get()
            ->map(fn ($p) => $p->unique_id . ':' . $p->version . ':' . ($p->enabled ? '1' : '0'))
            ->join('|');
        return response()->json(['success' => true, 'sig' => md5($sig)]);
    }

    public function upload(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $request->validate(['plugin' => 'required|file|mimes:zip|max:10240']);

        $file = $request->file('plugin');
        $zip = new ZipArchive;
        if ($zip->open($file->getPathname()) !== true) {
            return response()->json(['success' => false, 'error' => 'Cannot open zip file.'], 400);
        }

        $manifestJson = $zip->getFromName('plugin.json');
        if ($manifestJson === false) {
            $zip->close();
            return response()->json(['success' => false, 'error' => 'Missing plugin.json in zip root.'], 400);
        }

        $manifest = json_decode($manifestJson, true);
        if (!$manifest || empty($manifest['unique_id']) || empty($manifest['name'])) {
            $zip->close();
            return response()->json(['success' => false, 'error' => 'Invalid plugin.json: unique_id and name required.'], 400);
        }

        $uniqueId = $manifest['unique_id'];

        if (Plugin::where('unique_id', $uniqueId)->exists()) {
            $zip->close();
            return response()->json(['success' => false, 'error' => 'Plugin "' . $uniqueId . '" already installed.'], 409);
        }

        $extractPath = storage_path('app/plugins/' . $uniqueId);
        $zip->extractTo($extractPath);
        $zip->close();

        $hooks = $manifest['hooks'] ?? [];

        Plugin::create([
            'unique_id' => $uniqueId,
            'name' => $manifest['name'],
            'version' => $manifest['version'] ?? '1.0.0',
            'description' => $manifest['description'] ?? '',
            'author' => $manifest['author'] ?? '',
            'license' => $manifest['license'] ?? '',
            'icon' => $manifest['icon'] ?? 'fa-plug',
            'hooks' => $hooks,
            'enabled' => true,
        ]);

        $admin = $this->getUser($request);
        ActivityLog::create([
            'action' => 'plugin:install',
            'user_id' => $admin->id,
            'metadata' => json_encode(['plugin' => $uniqueId, 'name' => $manifest['name']]),
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'plugin' => [
                'unique_id' => $uniqueId,
                'name' => $manifest['name'],
                'version' => $manifest['version'] ?? '1.0.0',
                'description' => $manifest['description'] ?? '',
                'author' => $manifest['author'] ?? '',
                'hooks' => $hooks,
            ]
        ]);
    }

    public function toggle(Request $request, string $uniqueId): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $plugin = Plugin::where('unique_id', $uniqueId)->firstOrFail();
        $plugin->update(['enabled' => !$plugin->enabled]);

        $admin = $this->getUser($request);
        ActivityLog::create([
            'action' => $plugin->enabled ? 'plugin:enable' : 'plugin:disable',
            'user_id' => $admin->id,
            'metadata' => json_encode(['plugin' => $uniqueId]),
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true, 'enabled' => $plugin->enabled]);
    }

    public function destroy(Request $request, string $uniqueId): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $plugin = Plugin::where('unique_id', $uniqueId)->firstOrFail();
        $path = $plugin->storagePath();
        if (is_dir($path)) {
            $this->rmdirRecursive($path);
        }
        $plugin->delete();

        $admin = $this->getUser($request);
        ActivityLog::create([
            'action' => 'plugin:uninstall',
            'user_id' => $admin->id,
            'metadata' => json_encode(['plugin' => $uniqueId]),
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true]);
    }

    public function serveAsset(Request $request, string $uniqueId, string $path = '')
    {
        $plugin = Plugin::where('unique_id', $uniqueId)->first();
        if (!$plugin || !$plugin->enabled) {
            abort(404);
        }

        $fullPath = $plugin->storagePath() . '/' . ltrim($path, '/');
        $fullPath = realpath($fullPath);

        if (!$fullPath || !str_starts_with($fullPath, realpath(storage_path('app/plugins/' . $uniqueId)))) {
            abort(404);
        }

        if (!file_exists($fullPath) || is_dir($fullPath)) {
            abort(404);
        }

        $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
        $mimes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'json' => 'application/json',
            'html' => 'text/html',
        ];

        return response()->file($fullPath, [
            'Content-Type' => $mimes[$ext] ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function activeHooks(Request $request): JsonResponse
    {
        $plugins = Plugin::where('enabled', true)->get();
        $allHooks = ['css' => [], 'js' => [], 'admin_tabs' => [], 'sidebar_items' => [], 'server_tabs' => []];
        foreach ($plugins as $plugin) {
            $manifestPath = $plugin->storagePath() . '/plugin.json';
            $hooks = [];
            if (file_exists($manifestPath)) {
                $manifest = json_decode(file_get_contents($manifestPath), true);
                $hooks = $manifest['hooks'] ?? [];
            }
            foreach ($allHooks as $key => &$list) {
                if (!empty($hooks[$key]) && is_array($hooks[$key])) {
                    foreach ($hooks[$key] as $item) {
                        if (is_string($item) && ($key === 'css' || $key === 'js')) {
                            $list[] = ['_plugin' => $plugin->unique_id, 'src' => $item, 'url' => $plugin->assetUrl($item)];
                        } elseif (is_array($item)) {
                            $item['_plugin'] = $plugin->unique_id;
                            if (($key === 'css' || $key === 'js') && !empty($item['src'])) {
                                $item['url'] = $plugin->assetUrl($item['src']);
                            }
                            $list[] = $item;
                        }
                    }
                }
            }
            unset($list);
        }
        return response()->json(['success' => true, 'hooks' => $allHooks]);
    }

    public function storeConfig(Request $request, string $uniqueId): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $plugin = Plugin::where('unique_id', $uniqueId)->firstOrFail();
        $plugin->update(['plugin_config' => $request->input('config', [])]);
        return response()->json(['success' => true]);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
