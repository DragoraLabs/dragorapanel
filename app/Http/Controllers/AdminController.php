<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Allocation;
use App\Models\DatabaseHost;
use App\Models\Location;
use App\Models\Node;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Session;
use App\Models\User;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    private function getUser(Request $request): ?User
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

    // ── Users ──

    public function usersIndex(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $users = User::withCount('servers')->orderBy('id')->get();
        return response()->json(['success' => true, 'users' => $users]);
    }

    public function usersStore(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'role' => 'nullable|string|in:member,admin',
        ]);
        $data['password'] = Hash::make($data['password']);
        $data['role'] ??= 'member';
        $user = User::create($data);
        ActivityLog::create(['action' => 'admin:user:create', 'user_id' => $this->getUser($request)->id, 'metadata' => json_encode(['target' => $user->email]), 'ip_address' => $request->ip()]);
        return response()->json(['success' => true, 'user' => $user]);
    }

    public function usersUpdate(Request $request, User $user): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate([
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'role' => 'nullable|string|in:member,admin',
            'password' => 'nullable|min:6',
            'banned' => 'nullable|boolean',
        ]);
        if (isset($data['password'])) $data['password'] = Hash::make($data['password']);
        $user->update(array_filter($data, fn ($v) => $v !== null));
        return response()->json(['success' => true, 'user' => $user]);
    }

    public function usersDestroy(Request $request, User $user): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        if ($user->id === $this->getUser($request)->id) return response()->json(['success' => false, 'error' => 'Cannot delete yourself.'], 400);
        $user->servers()->delete();
        $user->delete();
        return response()->json(['success' => true]);
    }

    // ── Locations ──

    public function locationsIndex(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        return response()->json(['success' => true, 'locations' => Location::withCount('allocations')->orderBy('short_code')->get()]);
    }

    public function locationsStore(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate(['short_code' => 'required|string|max:10|unique:locations,short_code', 'long_name' => 'required|string|max:255', 'description' => 'nullable|string']);
        $loc = Location::create($data);
        return response()->json(['success' => true, 'location' => $loc]);
    }

    public function locationsUpdate(Request $request, Location $location): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate(['short_code' => 'nullable|string|max:10|unique:locations,short_code,' . $location->id, 'long_name' => 'nullable|string|max:255', 'description' => 'nullable|string']);
        $location->update(array_filter($data));
        return response()->json(['success' => true, 'location' => $location]);
    }

    public function locationsDestroy(Request $request, Location $location): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $location->delete();
        return response()->json(['success' => true]);
    }

    // ── Allocations ──

    public function allocationsIndex(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $allocations = Allocation::with(['location', 'node', 'server'])->orderBy('id')->get();
        return response()->json(['success' => true, 'allocations' => $allocations]);
    }

    public function allocationsStore(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate([
            'node_id' => 'nullable|integer|exists:nodes,id',
            'location_id' => 'required|exists:locations,id',
            'ip' => 'required|string|max:45',
            'port' => 'required|integer|min:1|max:65535',
        ]);
        $data['assigned'] = false;
        $alloc = Allocation::create($data);
        return response()->json(['success' => true, 'allocation' => $alloc]);
    }

    public function allocationsDestroy(Request $request, Allocation $allocation): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $allocation->delete();
        return response()->json(['success' => true]);
    }

    // ── Settings ──

    public function settingsIndex(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $settings = Setting::all()->pluck('value', 'key');
        if ($settings->has('panel:logo') && $settings['panel:logo']) {
            $val = $settings['panel:logo'];
            // Extract filename from old URLs or direct filenames
            $filename = basename($val);
            if ($filename && pathinfo($filename, PATHINFO_EXTENSION)) {
                $settings['panel:logo'] = route('panel.logo', ['file' => $filename]);
            }
        }
        return response()->json(['success' => true, 'settings' => $settings]);
    }

    public function settingsUpdate(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate(['settings' => 'required|array']);
        foreach ($data['settings'] as $key => $value) {
            Setting::set($key, $value);
        }
        return response()->json(['success' => true]);
    }

    public function databaseTest(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', Setting::get('db:host', '127.0.0.1'), (int) Setting::get('db:port', 3306));
            $conn = new \PDO($dsn, Setting::get('db:username', 'root'), Setting::get('db:password', ''), [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $version = $conn->query('SELECT VERSION()')->fetchColumn();
            return response()->json(['success' => true, 'version' => $version]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // ── Database Hosts ──

    public function databaseHostsIndex(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $hosts = DatabaseHost::orderBy('name')->get();
        foreach ($hosts as $h) {
            $h->databases_used = $h->currentDatabaseCount();
        }
        return response()->json(['success' => true, 'hosts' => $hosts]);
    }

    public function databaseHostsStore(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate([
            'name' => 'required|string|max:64',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:64',
            'password' => 'nullable|string|max:255',
            'max_databases' => 'nullable|integer|min:0',
            'is_enabled' => 'nullable|boolean',
        ]);
        $data['password'] = $data['password'] ?? '';
        $data['is_enabled'] = $data['is_enabled'] ?? true;
        $host = DatabaseHost::create($data);
        return response()->json(['success' => true, 'host' => $host]);
    }

    public function databaseHostsUpdate(Request $request, DatabaseHost $databaseHost): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate([
            'name' => 'sometimes|string|max:64',
            'host' => 'sometimes|string|max:255',
            'port' => 'sometimes|integer|min:1|max:65535',
            'username' => 'sometimes|string|max:64',
            'password' => 'nullable|string|max:255',
            'max_databases' => 'nullable|integer|min:0',
            'is_enabled' => 'nullable|boolean',
        ]);
        if (array_key_exists('password', $data) && ($data['password'] === '' || $data['password'] === null)) {
            unset($data['password']);
        }
        $databaseHost->update($data);
        return response()->json(['success' => true, 'host' => $databaseHost]);
    }

    public function databaseHostsDestroy(Request $request, DatabaseHost $databaseHost): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        if ($databaseHost->currentDatabaseCount() > 0) {
            return response()->json(['success' => false, 'error' => 'This host still has databases attached to it.'], 422);
        }
        $databaseHost->delete();
        return response()->json(['success' => true]);
    }

    public function databaseHostTest(Request $request, DatabaseHost $databaseHost): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        try {
            $version = $databaseHost->pdo()->query('SELECT VERSION()')->fetchColumn();
            return response()->json(['success' => true, 'version' => $version]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    public function logoUpload(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $request->validate(['logo' => 'required|image|mimes:png,jpg,jpeg,gif,webp|max:2048']);
        $path = $request->file('logo')->store('logo', 'public');
        $filename = basename($path);
        Setting::set('panel:logo', $filename);
        return response()->json(['success' => true, 'url' => route('panel.logo', ['file' => $filename])]);
    }

    public function getLogo(Request $request, string $file)
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (!$disk->exists('logo/' . $file)) {
            $file = Setting::get('panel:logo');
            if (!$file || !$disk->exists('logo/' . $file)) {
                abort(404);
            }
        }
        return response()->file($disk->path('logo/' . $file));
    }

    public function getDefaultLogo()
    {
        $path = storage_path('app/images/logo.png');
        if (!file_exists($path)) abort(404);
        return response()->file($path);
    }

    public function getAvatar()
    {
        $path = storage_path('app/images/user.png');
        if (!file_exists($path)) abort(404);
        return response()->file($path);
    }

    public function getBackgroundImage()
    {
        $path = storage_path('app/images/background.png');
        if (!file_exists($path)) abort(404);
        return response()->file($path);
    }

    // ── Activity Log ──

    public function activityIndex(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $logs = ActivityLog::with('user')->latest('created_at')->limit(100)->get();
        return response()->json(['success' => true, 'logs' => $logs]);
    }

    // ── Panel Dashboard Stats ──

    public function stats(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $nodes = Node::count();
        $nodesOnline = Node::where('status', 'online')->count();
        return response()->json(['success' => true, 'stats' => [
            'users' => User::count(),
            'servers' => Server::count(),
            'nodes' => $nodes,
            'nodes_online' => $nodesOnline,
            'locations' => Location::count(),
            'backups' => \App\Models\Backup::count(),
        ]]);
    }
}
