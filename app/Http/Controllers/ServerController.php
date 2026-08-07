<?php

namespace App\Http\Controllers;

use App\Models\Egg;
use App\Models\Server;
use App\Models\Session;
use App\Models\Subuser;
use App\Models\ActivityLog;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    private function getUser(Request $request): ?\App\Models\User
    {
        return ApiAuth::user($request);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        $cacheKey = 'servers:list:v' . Cache::get('servers:version', 1) . ':' . ($user->isAdmin() ? 'admin' : 'user:' . $user->id);
        $servers = Cache::remember($cacheKey, 5, function () use ($user) {
            if ($user->isAdmin()) {
                return Server::with('user', 'egg')->orderBy('created_at', 'desc')->get()->map(function ($s) {
                    $data = $s->toArray();
                    $data['user_email'] = $s->user->email ?? null;
                    $data['is_subuser'] = false;
                    $data['subuser_permissions'] = null;
                    $data['egg_name'] = $s->egg->name ?? null;
                    return $data;
                });
            }
            $owned = Server::with('egg')->where('user_id', $user->id)->get();
            $subuserIds = Subuser::where('user_id', $user->id)->pluck('server_id');
            $subuserServers = Server::with('egg')->whereIn('id', $subuserIds)->get();
            return $owned->concat($subuserServers)->unique('id')->sortByDesc('created_at')->values()->map(function ($s) use ($user) {
                $data = $s->toArray();
                $sub = Subuser::where('user_id', $user->id)->where('server_id', $s->id)->first();
                $data['is_subuser'] = (bool)$sub;
                $data['subuser_permissions'] = $sub ? $sub->getPermissionList() : null;
                $data['egg_name'] = $s->egg->name ?? null;
                return $data;
            });
        });

        // Attach a display address (node host + server port) to every server
        $nodeIds = collect($servers)->pluck('node_id')->filter()->unique()->values();
        $nodes = $nodeIds->isEmpty() ? collect() : \App\Models\Node::whereIn('id', $nodeIds)->get()->keyBy('id');
        $servers = collect($servers)->map(function ($s) use ($nodes) {
            $node = !empty($s['node_id']) ? ($nodes[$s['node_id']] ?? null) : null;
            if ($node) {
                $host = $node->fqdn ?: $node->ip_address ?: 'localhost';
                $s['address'] = $host . ':' . ($s['port'] ?: 25565);
            } else {
                $s['address'] = null;
            }
            return $s;
        });

        return response()->json(['success' => true, 'servers' => $servers]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        $server = Server::find($id);
        if (!$server || (!$user->isAdmin() && $server->user_id !== $user->id)) {
            // allow subusers
            $sub = $server ? Subuser::where('user_id', $user->id)->where('server_id', $id)->first() : null;
            if (!$sub) {
                return response()->json(['success' => false, 'error' => 'Server not found.'], 404);
            }
        }

        $data = $server->toArray();
        if ($server->egg) {
            $data['egg'] = [
                'id' => $server->egg->id,
                'name' => $server->egg->name,
                'description' => $server->egg->description,
                'docker_image' => $server->egg->docker_image,
                'startup_command' => $server->egg->startup_command,
                'default_version' => $server->egg->default_version,
                'type' => $server->egg->type,
            ];
        }
        $sub = ($user->isAdmin() || $server->user_id === $user->id)
            ? null
            : Subuser::where('user_id', $user->id)->where('server_id', $id)->first();
        $data['is_subuser'] = (bool)$sub;
        $data['subuser_permissions'] = $sub ? $sub->getPermissionList() : null;
        if ($server->node_id) {
            $node = $server->node;
            if ($node) {
                $host = $node->fqdn ?: $node->ip_address ?: 'localhost';
                $data['address'] = $host . ':' . ($server->port ?: 25565);
                $data['agent_url'] = "http://{$host}:" . ($node->api_port ?: $node->port ?: 8080);
                // Only expose agent token to owners/admins; subusers use panel endpoints
                if ($user->isAdmin() || $server->user_id === $user->id) {
                    $data['agent_token'] = $node->token;
                } else {
                    $data['agent_token'] = null;
                }
            }
        }
        return response()->json(['success' => true, 'server' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        $rules = [
            'name' => 'required|string|max:255',
            'egg_id' => 'nullable|integer|exists:eggs,id',
            'type' => 'string|max:50',
            'version' => 'nullable|string|max:50',
            'java_version' => 'nullable|string|max:10',
            'docker_image' => 'nullable|string|max:255',
            'memory_mb' => 'integer|min:256',
            'storage_mb' => 'integer|min:1024',
            'port' => 'integer|nullable',
        ];
        if ($user->isAdmin()) {
            $rules['user_id'] = 'required|integer|exists:users,id';
            $rules['node_id'] = 'nullable|integer|exists:nodes,id';
        }
        $data = $request->validate($rules);

        Cache::increment('servers:version');

        // Auto-populate from egg if provided
        $egg = null;
        if (!empty($data['egg_id'])) {
            $egg = Egg::find($data['egg_id']);
        }

        $server = Server::create([
            'egg_id' => $data['egg_id'] ?? null,
            'user_id' => $user->isAdmin() ? $data['user_id'] : $user->id,
            'node_id' => $user->isAdmin() ? ($data['node_id'] ?? null) : null,
            'name' => $data['name'],
            'type' => $data['type'] ?? ($egg->type ?? 'minecraft'),
            'version' => $data['version'] ?? ($egg->default_version ?? '1.21.4'),
            'java_version' => $data['java_version'] ?? ($egg->java_version ?? '21'),
            'docker_image' => $data['docker_image'] ?? $egg->docker_image ?? null,
            'memory_mb' => (int)($data['memory_mb'] ?? 1024),
            'storage_mb' => (int)($data['storage_mb'] ?? 5120),
            'port' => $data['port'] ?? null,
        ]);

        return response()->json(['success' => true, 'server' => $server]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        $server = Server::find($id);
        if (!$server || (!$user->isAdmin() && $server->user_id !== $user->id)) {
            return response()->json(['success' => false, 'error' => 'Server not found.'], 404);
        }

        $allowed = ['name', 'type', 'version', 'java_version', 'docker_image', 'status', 'memory_mb', 'storage_mb', 'port', 'ip_address', 'user_id', 'node_id'];
        $data = $request->only($allowed);
        if ($user->isAdmin()) {
            // allow user_id and node_id changes for admins
        } else {
            unset($data['user_id'], $data['node_id']);
        }
        $data = array_filter($data, fn($v) => $v !== null);

        if (empty($data)) {
            return response()->json(['success' => false, 'error' => 'No valid fields to update.'], 400);
        }

        $server->update($data);
        $server->refresh();
        Cache::increment('servers:version');

        $out = $server->toArray();
        if ($server->node_id && $server->node) {
            $host = $server->node->fqdn ?: $server->node->ip_address ?: 'localhost';
            $out['address'] = $host . ':' . ($server->port ?: 25565);
        }

        return response()->json(['success' => true, 'server' => $out]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        $server = Server::find($id);
        if (!$server || (!$user->isAdmin() && $server->user_id !== $user->id)) {
            return response()->json(['success' => false, 'error' => 'Server not found.'], 404);
        }

        $server->delete();
        Cache::increment('servers:version');

        return response()->json(['success' => true]);
    }

    public function reinstall(Request $request, int $id): JsonResponse
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['success' => false, 'error' => 'Not authenticated.'], 401);
        }

        $server = Server::find($id);
        if (!$server || (!$user->isAdmin() && $server->user_id !== $user->id)) {
            return response()->json(['success' => false, 'error' => 'Server not found.'], 404);
        }

        // Tell the agent to stop container, wipe files, and re-download
        // Async via Redis pub/sub (fast), DB queue as fallback — no blocking HTTP call
        $queued = false;
        if ($server->node_id) {
            $redisOk = app(\App\Services\RedisBroker::class)->publishReinstall($server->node_id, $id);
            if (!$redisOk) {
                $server->queueCommand('reinstall:' . $id);
                $queued = true;
            }
        }
        $via = $queued ? 'queue' : 'redis';

        $server->update([
            'status' => 'offline',
            'console_logs' => null,
            'command_queue' => '[]',
            'memory_used_mb' => null,
            'cpu_percent' => null,
        ]);

        ActivityLog::create([
            'action' => 'server:reinstall',
            'user_id' => $user->id,
            'metadata' => json_encode(['server_id' => $id, 'server_name' => $server->name]),
            'ip_address' => $request->ip(),
        ]);

        Cache::increment('servers:version');

        return response()->json(['success' => true, 'message' => 'Server reinstalled.', 'via' => $via]);
    }
}
