<?php

namespace App\Http\Controllers;

use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use App\Models\Session;
use App\Models\ActivityLog;
use App\Services\ApiAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class NodeController extends Controller
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

    private function authenticateNode(Request $request): ?Node
    {
        $token = $request->bearerToken();
        if (!$token) return null;
        return Node::where('token', $token)->first();
    }

    // ── Panel Admin: CRUD ──

    public function index(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $nodes = Node::with('location')->withCount('servers')->orderBy('name')->get();
        return response()->json(['success' => true, 'nodes' => $nodes]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $node = Node::with('location')->withCount('servers')->find($id);
        if (!$node) return response()->json(['success' => false, 'error' => 'Node not found.'], 404);
        return response()->json(['success' => true, 'node' => $node]);
    }

    public function store(Request $request): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'fqdn' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:45',
            'port' => 'nullable|integer|min:1|max:65535',
            'location_id' => 'nullable|integer|exists:locations,id',
            'memory_mb' => 'nullable|integer|min:1',
            'storage_mb' => 'nullable|integer|min:1',
            'cpu_cores' => 'nullable|integer|min:1',
            'api_host' => 'nullable|string|max:45',
            'api_port' => 'nullable|integer|min:1|max:65535',
            'tls_enabled' => 'nullable|boolean',
            'tls_cert' => 'nullable|string',
            'tls_key' => 'nullable|string',
            'storage_servers' => 'nullable|string',
            'storage_backups' => 'nullable|string',
            'runtime_engine' => 'nullable|string|max:50',
            'runtime_network' => 'nullable|string|max:100',
            'features' => 'nullable|json',
            'limits_cpu' => 'nullable|integer|min:1',
            'limits_disk' => 'nullable|integer|min:1',
            'plugins_repository' => 'nullable|string',
            'security_verify_tls' => 'nullable|boolean',
            'security_mounts' => 'nullable|json',
            'cluster_enabled' => 'nullable|boolean',
        ]);
        $data['token'] = Str::random(64);
        $data['uuid'] = (string) Str::uuid();
        $node = Node::create($data);
        ActivityLog::create([
            'action' => 'admin:node:create',
            'user_id' => $this->getUser($request)->id,
            'metadata' => json_encode(['target' => $node->name]),
            'ip_address' => $request->ip(),
        ]);
        return response()->json(['success' => true, 'node' => $node, 'raw_token' => $data['token']]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $node = Node::find($id);
        if (!$node) return response()->json(['success' => false, 'error' => 'Node not found.'], 404);
        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'fqdn' => 'nullable|string|max:255',
            'ip_address' => 'nullable|string|max:45',
            'port' => 'nullable|integer|min:1|max:65535',
            'location_id' => 'nullable|integer|exists:locations,id',
            'memory_mb' => 'nullable|integer|min:1',
            'storage_mb' => 'nullable|integer|min:1',
            'cpu_cores' => 'nullable|integer|min:1',
            'api_host' => 'nullable|string|max:45',
            'api_port' => 'nullable|integer|min:1|max:65535',
            'tls_enabled' => 'nullable|boolean',
            'tls_cert' => 'nullable|string',
            'tls_key' => 'nullable|string',
            'storage_servers' => 'nullable|string',
            'storage_backups' => 'nullable|string',
            'runtime_engine' => 'nullable|string|max:50',
            'runtime_network' => 'nullable|string|max:100',
            'features' => 'nullable|json',
            'limits_cpu' => 'nullable|integer|min:1',
            'limits_disk' => 'nullable|integer|min:1',
            'plugins_repository' => 'nullable|string',
            'security_verify_tls' => 'nullable|boolean',
            'security_mounts' => 'nullable|json',
            'cluster_enabled' => 'nullable|boolean',
            'status' => 'nullable|string|in:online,offline',
        ]);
        $node->update(array_filter($data, fn($v) => !is_null($v)));
        return response()->json(['success' => true, 'node' => $node->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $node = Node::find($id);
        if (!$node) return response()->json(['success' => false, 'error' => 'Node not found.'], 404);
        Server::where('node_id', $id)->update(['node_id' => null]);
        Allocation::where('node_id', $id)->update(['node_id' => null]);
        $node->delete();
        ActivityLog::create([
            'action' => 'admin:node:delete',
            'user_id' => $this->getUser($request)->id,
            'metadata' => json_encode(['target' => $node->name]),
            'ip_address' => $request->ip(),
        ]);
        return response()->json(['success' => true]);
    }

    public function regenerate(Request $request, int $id): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $node = Node::find($id);
        if (!$node) return response()->json(['success' => false, 'error' => 'Node not found.'], 404);
        $node->update(['token' => Str::random(64)]);
        return response()->json(['success' => true, 'raw_token' => $node->fresh()->token]);
    }

    public function config(Request $request, int $id): JsonResponse
    {
        $block = $this->requireAdmin($request); if ($block) return $block;
        $node = Node::with('location')->find($id);
        if (!$node) return response()->json(['success' => false, 'error' => 'Node not found.'], 404);
        return response()->json(['success' => true, 'config' => $this->generateYamlConfig($node)]);
    }

    private function generateYamlConfig(Node $node): array
    {
        $features = $node->features ?: [
            'auto_updates' => true, 'metrics' => true, 'websocket' => true,
            'scheduler' => true, 'telemetry' => false,
        ];
        return [
            'app' => [
                'debug' => false,
                'node' => [
                    'id' => $node->id,
                    'uuid' => $node->uuid ?? '',
                    'secret' => $node->token,
                ],
                'api' => [
                    'host' => $node->api_host ?? '0.0.0.0',
                    'port' => $node->api_port ?? $node->port ?? 8080,
                ],
            ],
            'tls' => [
                'enabled' => (bool) $node->tls_enabled,
                'cert' => $node->tls_cert ?? '/etc/ssl/dragorapanel/fullchain.pem',
                'key' => $node->tls_key ?? '/etc/ssl/dragorapanel/privkey.pem',
            ],
            'storage' => [
                'servers' => $node->storage_servers ?? '/var/lib/dragorapanel/servers',
                'backups' => $node->storage_backups ?? '/var/lib/dragorapanel/backups',
            ],
            'runtime' => [
                'engine' => $node->runtime_engine ?? 'docker',
                'network' => $node->runtime_network ?? 'dragora',
            ],
            'features' => $features,
            'limits' => [
                'cpu' => $node->limits_cpu ?? 800,
                'memory' => $node->memory_mb ?? 32768,
                'disk' => $node->limits_disk ?? 500000,
            ],
            'plugins' => [
                'repository' => $node->plugins_repository ?? 'https://plugins.dragoralabs.qzz.io',
            ],
            'security' => [
                'verify_tls' => (bool) $node->security_verify_tls,
                'mounts' => $node->security_mounts ?? [],
            ],
            'cluster' => [
                'enabled' => (bool) $node->cluster_enabled,
            ],
        ];
    }

    // ── Agent-Facing Endpoints ──

    public function agentPing(Request $request): JsonResponse
    {
        $node = $this->authenticateNode($request);
        if (!$node) return response()->json(['success' => false, 'error' => 'Invalid token.'], 401);
        $node->update(['status' => 'online', 'last_seen_at' => now()]);
        return response()->json(['success' => true, 'node' => $node->fresh()]);
    }

    public function agentReport(Request $request): JsonResponse
    {
        $node = $this->authenticateNode($request);
        if (!$node) return response()->json(['success' => false, 'error' => 'Invalid token.'], 401);
        $data = $request->validate([
            'cpu_percent' => 'nullable|numeric|min:0|max:100',
            'memory_used_mb' => 'nullable|integer|min:0',
            'disk_used_mb' => 'nullable|integer|min:0',
            'cpu_cores' => 'nullable|integer|min:0',
        ]);
        $node->update(array_merge($data, [
            'status' => 'online',
            'last_seen_at' => now(),
        ]));
        return response()->json(['success' => true]);
    }

    public function agentServers(Request $request): JsonResponse
    {
        $node = $this->authenticateNode($request);
        if (!$node) return response()->json(['success' => false, 'error' => 'Invalid token.'], 401);
        $servers = Server::where('node_id', $node->id)->get();
        return response()->json(['success' => true, 'servers' => $servers]);
    }

    public function agentServerCommands(Request $request, int $serverId): JsonResponse
    {
        $node = $this->authenticateNode($request);
        if (!$node) return response()->json(['success' => false, 'error' => 'Invalid token.'], 401);
        $server = Server::where('id', $serverId)->where('node_id', $node->id)->first();
        if (!$server) return response()->json(['success' => false, 'error' => 'Server not found on this node.'], 404);
        $commands = $server->shiftCommands();
        return response()->json(['success' => true, 'commands' => $commands]);
    }

    public function agentServerStatus(Request $request, int $serverId): JsonResponse
    {
        $node = $this->authenticateNode($request);
        if (!$node) return response()->json(['success' => false, 'error' => 'Invalid token.'], 401);
        $server = Server::where('id', $serverId)->where('node_id', $node->id)->first();
        if (!$server) return response()->json(['success' => false, 'error' => 'Server not found on this node.'], 404);
        $data = $request->validate([
            'status' => 'required|string|in:starting,running,stopping,offline,crashed',
            'memory_used_mb' => 'nullable|integer',
            'cpu_percent' => 'nullable|numeric',
        ]);
        $server->update($data);
        Cache::increment('servers:version');
        return response()->json(['success' => true]);
    }

    public function agentServerLog(Request $request, int $serverId): JsonResponse
    {
        $node = $this->authenticateNode($request);
        if (!$node) return response()->json(['success' => false, 'error' => 'Invalid token.'], 401);
        $server = Server::where('id', $serverId)->where('node_id', $node->id)->first();
        if (!$server) return response()->json(['success' => false, 'error' => 'Server not found on this node.'], 404);
        $data = $request->validate(['line' => 'required|string']);
        // Log lines are not stored anymore — the console only exposes a URL
        // to the raw container logs.
        return response()->json(['success' => true]);
    }

    // ── Allocation helpers for agent ──

    public function agentAllocatePort(Request $request): JsonResponse
    {
        $node = $this->authenticateNode($request);
        if (!$node) return response()->json(['success' => false, 'error' => 'Invalid token.'], 401);
        $data = $request->validate(['ip' => 'required|string|max:45']);
        $used = Allocation::where('node_id', $node->id)->pluck('port')->toArray();
        $port = null;
        for ($p = 25565; $p <= 26000; $p++) {
            if (!in_array($p, $used)) { $port = $p; break; }
        }
        if (!$port) return response()->json(['success' => false, 'error' => 'No ports available.'], 400);
        $alloc = Allocation::create([
            'node_id' => $node->id,
            'location_id' => $node->location_id,
            'ip' => $data['ip'],
            'port' => $port,
            'assigned' => false,
        ]);
        return response()->json(['success' => true, 'allocation' => $alloc]);
    }
}
