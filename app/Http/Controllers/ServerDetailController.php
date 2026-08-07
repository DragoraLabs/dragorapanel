<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\DatabaseHost;
use App\Models\FirewallRule;
use App\Models\Schedule;
use App\Models\ScheduleTask;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Session;
use App\Models\Setting;
use App\Models\Subuser;
use App\Models\User;
use App\Services\ApiAuth;
use App\Services\RedisBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ServerDetailController extends Controller
{
    private function serverRoot(int $serverId): string
    {
        $path = env('SERVER_STORAGE_PATH') ?: base_path('node_agent_go/servers');
        return rtrim($path, '\\/') . DIRECTORY_SEPARATOR . $serverId;
    }

    private function getUser(Request $request): ?User
    {
        return ApiAuth::user($request);
    }

    private function getServer(Request $request, int $id): ?Server
    {
        $user = $this->getUser($request);
        if (!$user) return null;
        $server = Server::find($id);
        if (!$server) return null;
        if ($user->isAdmin()) return $server;
        if ($server->user_id === $user->id) return $server;
        // check subuser
        $subuser = Subuser::where('user_id', $user->id)->where('server_id', $id)->first();
        if ($subuser) return $server;
        return null;
    }

    private function checkAccess(Request $request, int $id): ?Server
    {
        $server = $this->getServer($request, $id);
        if (!$server) return null;
        return $server;
    }

    private function checkSubuserPerm(Server $server, User $user, string $perm): bool
    {
        if ($user->isAdmin() || $server->user_id === $user->id) return true;
        $subuser = Subuser::where('user_id', $user->id)->where('server_id', $server->id)->first();
        return $subuser && $subuser->hasPermission($perm);
    }

    // ── Console ──

    public function consoleSend(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'console')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['command' => 'required|string']);

        // Fast path: Redis pub/sub
        if ($server->node_id && app(RedisBroker::class)->publishCommand($server->node_id, $server->id, $data['command'])) {
            ActivityLog::create(['server_id' => $server->id, 'user_id' => $user->id, 'action' => 'server:console', 'metadata' => json_encode(['command' => $data['command'], 'via' => 'redis']), 'ip_address' => $request->ip()]);
            return response()->json(['success' => true, 'via' => 'redis']);
        }

        // Fallback: DB command queue
        $server->queueCommand($data['command']);
        ActivityLog::create(['server_id' => $server->id, 'user_id' => $user->id, 'action' => 'server:console', 'metadata' => json_encode(['command' => $data['command'], 'via' => 'queue']), 'ip_address' => $request->ip()]);
        return response()->json(['success' => true, 'via' => 'queue']);
    }

    public function consoleLogs(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);

        // Console logs are no longer stored. This endpoint only reports the
        // live status (Redis hash, DB row as fallback) for the status dot and
        // resource bars.
        $status = app(RedisBroker::class)->getServerStatus($id);
        if (!$status) {
            $status = [
                'status' => $server->status,
                'memory_used' => $server->memory_used_mb,
                'cpu_percent' => $server->cpu_percent,
                'disk_used' => $server->disk_used_mb,
            ];
        }
        return response()->json(['success' => true, 'logs' => [], 'source' => 'none', 'status' => $status]);
    }

    /**
     * Proxy the raw container logs from the node agent so the browser can
     * open them as plain text. Nothing is stored.
     */
    public function serverLogs(Request $request, int $id)
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'console')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $node = $server->node;
        if (!$node || !$node->token) {
            return response('This server has no node assigned.', 502);
        }

        $host = $node->api_host ?: $node->fqdn ?: $node->ip_address;
        // 0.0.0.0 / :: are bind addresses, not routable — use localhost.
        if ($host === '0.0.0.0' || $host === '::' || $host === '') $host = '127.0.0.1';
        $port = $node->api_port ?: $node->port ?: 8080;
        $scheme = $node->tls_enabled ? 'https' : 'http';
        $tail = max(1, min(5000, (int) $request->query('tail', 500)));
        $url = sprintf('%s://%s:%d/api/servers/%d/logs?tail=%d', $scheme, $host, $port, $server->id, $tail);
        $since = (int) $request->query('since', 0);
        if ($since > 0) $url .= '&since=' . $since;

        $resp = Http::withHeaders(['Authorization' => 'Bearer ' . $node->token])->timeout(10)->get($url);
        if ($resp->failed()) {
            return response('Failed to fetch logs from node (' . $resp->status() . ').', 502);
        }
        $logs = $resp->json('logs');
        if (!is_string($logs)) {
            return response('Unexpected response from node.', 502);
        }
        return response($logs, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function powerAction(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'console')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['action' => 'required|string|in:start,stop,restart,kill']);

        // A server must be attached to a node before it can be powered on.
        if (!$server->node_id) {
            return response()->json(['success' => false, 'error' => 'This server has no node assigned. Assign a node in the admin panel first.'], 400);
        }

        // Fast path: Redis pub/sub
        if ($server->node_id && app(RedisBroker::class)->publishPower($server->node_id, $server->id, $data['action'])) {
            $server->update(['status' => match($data['action']) {
                'start' => 'starting',
                'stop', 'kill' => 'stopping',
                'restart' => 'starting',
                default => $server->status
            }]);
            ActivityLog::create(['server_id' => $server->id, 'user_id' => $user->id, 'action' => 'server:power', 'metadata' => json_encode(['action' => $data['action'], 'via' => 'redis']), 'ip_address' => $request->ip()]);
            return response()->json(['success' => true, 'action' => $data['action'], 'via' => 'redis']);
        }

        // Fallback: DB command queue
        $server->queueCommand('power:' . $data['action']);
        $server->update(['status' => match($data['action']) {
            'start' => 'starting',
            'stop', 'kill' => 'stopping',
            'restart' => 'starting',
            default => $server->status
        }]);
        ActivityLog::create(['server_id' => $server->id, 'user_id' => $user->id, 'action' => 'server:power', 'metadata' => json_encode(['action' => $data['action'], 'via' => 'queue']), 'ip_address' => $request->ip()]);
        return response()->json(['success' => true, 'action' => $data['action'], 'via' => 'queue']);
    }

    // ── File Manager ──

    public function filesList(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'files')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $path = $request->query('path', '/');
        $root = $this->serverRoot($server->id);
        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }
        $full = $this->resolvePath($root, $path);
        if (!$full || !is_dir($full)) return response()->json(['success' => true, 'items' => [], 'path' => $path]);

        $isAdmin = $user->isAdmin();
        $items = [];
        foreach (scandir($full) as $name) {
            if ($name === '.' || $name === '..') continue;
            if (!$isAdmin && $this->isProtectedFileName($name)) continue;
            $fp = $full . DIRECTORY_SEPARATOR . $name;
            $size = @is_file($fp) ? (int) @filesize($fp) : 0;
            $last = @filemtime($fp);
            $items[] = [
                'name' => $name,
                'type' => @is_dir($fp) && !@is_link($fp) ? 'dir' : 'file',
                'size' => $size,
                'last_modified' => ($last === false ? null : $last),
            ];
        }
        return response()->json(['success' => true, 'items' => $items, 'path' => $path]);
    }

    public function filesCreateDir(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'files')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['path' => 'required|string', 'name' => 'required|string']);
        $root = $this->serverRoot($server->id);
        $parent = $this->resolvePath($root, $data['path']);
        if (!$parent || !is_dir($parent)) return response()->json(['success' => false, 'error' => 'Invalid path.'], 400);
        $target = $parent . DIRECTORY_SEPARATOR . basename($data['name']);
        if (file_exists($target)) return response()->json(['success' => false, 'error' => 'Already exists.'], 400);
        mkdir($target, 0755, true);
        return response()->json(['success' => true]);
    }

    public function filesCreateFile(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'files')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['path' => 'required|string', 'name' => 'required|string', 'content' => 'nullable|string']);
        $root = $this->serverRoot($server->id);
        $parent = $this->resolvePath($root, $data['path']);
        if (!$parent || !is_dir($parent)) return response()->json(['success' => false, 'error' => 'Invalid path.'], 400);
        $deny = $this->denyProtectedFile($user);
        if ($deny && $this->isProtectedFileName($data['name'])) return $deny;
        $target = $parent . DIRECTORY_SEPARATOR . basename($data['name']);
        file_put_contents($target, $data['content'] ?? '');
        return response()->json(['success' => true]);
    }

    public function filesRead(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'files')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $path = $request->query('path', '/');
        $root = $this->serverRoot($server->id);
        $full = $this->resolvePath($root, $path);
        if (!$full || !is_file($full)) return response()->json(['success' => false, 'error' => 'Invalid file.'], 400);
        $deny = $this->denyProtectedFile($user);
        if ($deny && $this->isProtectedFileName(basename($full))) return $deny;
        return response()->json(['success' => true, 'content' => file_get_contents($full)]);
    }

    public function filesWrite(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'files')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['path' => 'required|string', 'content' => 'required|string']);
        $root = $this->serverRoot($server->id);
        $full = $this->resolvePath($root, $data['path']);
        if (!$full || !is_file($full)) return response()->json(['success' => false, 'error' => 'Invalid file.'], 400);
        $deny = $this->denyProtectedFile($user);
        if ($deny && $this->isProtectedFileName(basename($full))) return $deny;
        file_put_contents($full, $data['content']);
        return response()->json(['success' => true]);
    }

    public function filesRename(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'files')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['path' => 'required|string', 'new_name' => 'required|string']);
        $root = $this->serverRoot($server->id);
        $full = $this->resolvePath($root, $data['path']);
        if (!$full || !file_exists($full)) return response()->json(['success' => false, 'error' => 'Not found.'], 400);
        $deny = $this->denyProtectedFile($user);
        if ($deny && ($this->isProtectedFileName(basename($full)) || $this->isProtectedFileName($data['new_name']))) return $deny;
        $new = dirname($full) . DIRECTORY_SEPARATOR . basename($data['new_name']);
        if (file_exists($new)) return response()->json(['success' => false, 'error' => 'Target exists.'], 400);
        rename($full, $new);
        return response()->json(['success' => true]);
    }

    public function filesDelete(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'files')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['path' => 'required|string']);
        $root = $this->serverRoot($server->id);
        $full = $this->resolvePath($root, $data['path']);
        if (!$full || !file_exists($full)) return response()->json(['success' => false, 'error' => 'Not found.'], 400);
        $deny = $this->denyProtectedFile($user);
        if ($deny && $this->isProtectedFileName(basename($full))) return $deny;
        if ($deny && is_dir($full) && $this->dirContainsProtectedFile($full)) return $deny;
        if (is_dir($full)) {
            $this->rmdirRecursive($full);
        } else {
            unlink($full);
        }
        return response()->json(['success' => true]);
    }

    public function filesUpload(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'files')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['path' => 'required|string', 'file' => 'required|file|max:102400']);
        $root = $this->serverRoot($server->id);
        $parent = $this->resolvePath($root, $data['path']);
        if (!$parent || !is_dir($parent)) return response()->json(['success' => false, 'error' => 'Invalid path.'], 400);
        $deny = $this->denyProtectedFile($user);
        if ($deny && $this->isProtectedFileName($request->file('file')->getClientOriginalName())) return $deny;
        $request->file('file')->move($parent, $request->file('file')->getClientOriginalName());
        return response()->json(['success' => true]);
    }

    // ── Backups ──

    public function backupsIndex(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'backups')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        return response()->json(['success' => true, 'backups' => $server->backups()->orderBy('id', 'desc')->get()]);
    }

    public function backupsStore(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'backups')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['name' => 'required|string']);
        $backup = Backup::create(['server_id' => $server->id, 'name' => $data['name'], 'status' => 'creating', 'size_bytes' => 0]);
        // Dispatch to the node agent: it zips the server dir and updates this
        // row with the real status/size. Async via Redis pub/sub, DB command
        // queue as fallback — no blocking HTTP call.
        $queued = false;
        if ($server->node_id) {
            $redisOk = app(RedisBroker::class)->publishBackup($server->node_id, $server->id, $backup->id);
            if (!$redisOk) {
                $server->queueCommand('backup:' . $backup->id);
                $queued = true;
            }
        } else {
            $backup->update(['status' => 'failed', 'size_bytes' => 0]);
            ActivityLog::create(['server_id' => $server->id, 'user_id' => $user->id, 'action' => 'server:backup:create', 'metadata' => json_encode(['backup_id' => $backup->id, 'name' => $data['name'], 'via' => 'no-node']), 'ip_address' => $request->ip()]);
            return response()->json(['success' => true, 'backup' => $backup->fresh()]);
        }
        ActivityLog::create(['server_id' => $server->id, 'user_id' => $user->id, 'action' => 'server:backup:create', 'metadata' => json_encode(['backup_id' => $backup->id, 'name' => $data['name'], 'via' => $queued ? 'queue' : 'redis']), 'ip_address' => $request->ip()]);
        return response()->json(['success' => true, 'backup' => $backup->fresh()]);
    }

    public function backupsDestroy(Request $request, int $id, Backup $backup): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $backup->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'backups')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        if ($backup->is_locked) return response()->json(['success' => false, 'error' => 'Backup is locked.'], 400);
        if ($backup->file_hash) {
            $storagePath = env('BACKUP_STORAGE_PATH') ?: base_path('node_agent_go/backups');
            $file = $storagePath . DIRECTORY_SEPARATOR . basename($backup->file_hash);
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $backup->delete();
        return response()->json(['success' => true]);
    }

    public function backupsLock(Request $request, int $id, Backup $backup): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $backup->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $backup->update(['is_locked' => !$backup->is_locked]);
        return response()->json(['success' => true, 'is_locked' => $backup->fresh()->is_locked]);
    }

    public function backupsRestore(Request $request, int $id, Backup $backup): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $backup->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'backups')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        if ($backup->status !== 'completed' || !$backup->file_hash) {
            return response()->json(['success' => false, 'error' => 'This backup has no archive to restore.'], 400);
        }

        $backup->update(['restore_status' => 'restoring']);

        $queued = false;
        if ($server->node_id) {
            $redisOk = app(RedisBroker::class)->publishRestore($server->node_id, $server->id, $backup->id);
            if (!$redisOk) {
                $server->queueCommand('restore:' . $backup->id);
                $queued = true;
            }
        } else {
            $backup->update(['restore_status' => 'failed']);
            return response()->json(['success' => false, 'error' => 'Server has no node assigned.'], 400);
        }

        ActivityLog::create(['server_id' => $server->id, 'user_id' => $user->id, 'action' => 'server:backup:restore', 'metadata' => json_encode(['backup_id' => $backup->id, 'name' => $backup->name, 'via' => $queued ? 'queue' : 'redis']), 'ip_address' => $request->ip()]);
        return response()->json(['success' => true]);
    }

    public function backupsDownload(Request $request, int $id, Backup $backup)
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $backup->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'backups')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        if (!$backup->file_hash) return response()->json(['success' => false, 'error' => 'No archive for this backup.'], 404);

        $storagePath = env('BACKUP_STORAGE_PATH') ?: base_path('node_agent_go/backups');
        $file = $storagePath . DIRECTORY_SEPARATOR . basename($backup->file_hash);
        if (!is_file($file)) return response()->json(['success' => false, 'error' => 'Archive file missing on disk.'], 404);

        return response()->download($file, $backup->name . '.zip');
    }

    // ── Databases ──

    public function databasesIndex(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'databases')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $hosts = DatabaseHost::where('is_enabled', true)->orderBy('name')->get(['id', 'name', 'host', 'port', 'username', 'max_databases']);
        foreach ($hosts as $h) {
            $h->databases_used = $h->currentDatabaseCount();
        }
        return response()->json([
            'success' => true,
            'databases' => $server->databases()->with('host')->get(),
            'hosts' => $hosts,
        ]);
    }

    public function databasesStore(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'databases')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate([
            'database_name' => 'required|string|max:40|regex:/^[A-Za-z0-9_]+$/',
            'remote_host' => 'nullable|string|max:64',
            'password' => 'required|string|min:8',
            'database_host_id' => 'required|integer|exists:database_hosts,id',
        ]);
        $host = DatabaseHost::where('id', $data['database_host_id'])->where('is_enabled', true)->first();
        if (!$host) return response()->json(['success' => false, 'error' => 'Database host not found or disabled.'], 422);
        if ($host->max_databases !== null && $host->currentDatabaseCount() >= $host->max_databases) {
            return response()->json(['success' => false, 'error' => 'This host has reached its database limit.'], 422);
        }

        $data['server_id'] = $server->id;
        $data['username'] = "s{$server->id}_" . Str::random(8);
        $data['remote_host'] ??= '%';

        try {
            $conn = $host->pdo();
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Cannot connect to the selected database host. ' . $e->getMessage()], 503);
        }

        $dbName = "s{$server->id}_" . $data['database_name'];
        $uname = $data['username'];
        $rhost = $data['remote_host'];
        $pass = $data['password'];
        try {
            $conn->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $conn->exec("CREATE USER IF NOT EXISTS '{$uname}'@'{$rhost}' IDENTIFIED BY '{$pass}'");
            $conn->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$uname}'@'{$rhost}'");
            $conn->exec('FLUSH PRIVILEGES');
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'MySQL error: ' . $e->getMessage()], 500);
        }

        $db = ServerDatabase::create($data);
        return response()->json(['success' => true, 'database' => $db->makeHidden('password')]);
    }

    public function databasesDestroy(Request $request, int $id, ServerDatabase $database): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $database->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'databases')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $host = $database->host;
        if ($host) {
            try {
                $conn = $host->pdo();
                $dbName = "s{$server->id}_" . $database->database_name;
                $conn->exec("DROP DATABASE IF EXISTS `{$dbName}`");
                $conn->exec("DROP USER IF EXISTS '{$database->username}'@'{$database->remote_host}'");
                $conn->exec('FLUSH PRIVILEGES');
            } catch (\Throwable $e) {
                // best-effort cleanup; still remove the record
            }
        }

        $database->delete();
        return response()->json(['success' => true]);
    }

    public function databasesResetPassword(Request $request, int $id, ServerDatabase $database): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $database->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'databases')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $newPass = Str::random(16);
        $host = $database->host;
        if ($host) {
            try {
                $conn = $host->pdo();
                $conn->exec("ALTER USER '{$database->username}'@'{$database->remote_host}' IDENTIFIED BY '{$newPass}'");
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'error' => 'MySQL error: ' . $e->getMessage()], 500);
            }
        }

        $database->update(['password' => $newPass]);
        return response()->json(['success' => true, 'password' => $newPass]);
    }

    // ── Schedules ──

    public function schedulesIndex(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'schedules')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        return response()->json(['success' => true, 'schedules' => $server->schedules()->with('tasks')->get()]);
    }

    public function schedulesStore(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'schedules')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate([
            'name' => 'required|string',
            'cron_minute' => 'required|string',
            'cron_hour' => 'required|string',
            'cron_day_of_week' => 'required|string',
            'cron_day_of_month' => 'required|string',
            'is_active' => 'nullable|boolean',
        ]);
        $data['server_id'] = $server->id;
        $schedule = Schedule::create($data);

        // Create tasks if provided
        if ($request->has('tasks')) {
            foreach ($request->input('tasks', []) as $i => $task) {
                ScheduleTask::create([
                    'schedule_id' => $schedule->id,
                    'sequence_id' => $i + 1,
                    'action' => $task['action'] ?? 'command',
                    'payload' => $task['payload'] ?? null,
                    'time_offset' => $task['time_offset'] ?? 0,
                ]);
            }
        }
        return response()->json(['success' => true, 'schedule' => $schedule->load('tasks')]);
    }

    public function schedulesUpdate(Request $request, int $id, Schedule $schedule): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $schedule->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'schedules')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate([
            'name' => 'nullable|string',
            'cron_minute' => 'nullable|string',
            'cron_hour' => 'nullable|string',
            'cron_day_of_week' => 'nullable|string',
            'cron_day_of_month' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $schedule->update(array_filter($data));
        return response()->json(['success' => true, 'schedule' => $schedule->fresh()->load('tasks')]);
    }

    public function schedulesDestroy(Request $request, int $id, Schedule $schedule): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $schedule->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'schedules')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);
        $schedule->delete();
        return response()->json(['success' => true]);
    }

    // ── Subusers ──

    public function subusersIndex(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        return response()->json(['success' => true, 'subusers' => $server->subusers()->with('user:id,email,first_name,last_name')->get()]);
    }

    public function subusersStore(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'subusers')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
            'permissions' => 'required|array',
        ]);
        $targetUser = User::where('email', $data['email'])->first();
        if ($targetUser->id === $server->user_id) return response()->json(['success' => false, 'error' => 'Cannot add owner as subuser.'], 400);
        if (Subuser::where('user_id', $targetUser->id)->where('server_id', $server->id)->exists()) return response()->json(['success' => false, 'error' => 'Already a subuser.'], 400);

        $subuser = Subuser::create([
            'user_id' => $targetUser->id,
            'server_id' => $server->id,
            'permissions' => json_encode($data['permissions']),
        ]);
        return response()->json(['success' => true, 'subuser' => $subuser->load('user:id,email,first_name,last_name')]);
    }

    public function subusersUpdate(Request $request, int $id, Subuser $subuser): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $subuser->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'subusers')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);

        $data = $request->validate(['permissions' => 'required|array']);
        $subuser->update(['permissions' => json_encode($data['permissions'])]);
        return response()->json(['success' => true, 'subuser' => $subuser->fresh()->load('user:id,email,first_name,last_name')]);
    }

    public function subusersDestroy(Request $request, int $id, Subuser $subuser): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server || $subuser->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Not found.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'subusers')) return response()->json(['success' => false, 'error' => 'No permission.'], 403);
        $subuser->delete();
        return response()->json(['success' => true]);
    }

    // ── Activity per server ──

    public function activityIndex(Request $request, int $id): JsonResponse
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $logs = ActivityLog::where('server_id', $server->id)->with('user')->latest()->limit(50)->get();
        return response()->json(['success' => true, 'logs' => $logs]);
    }

    // ── Firewall rules ──

    private function firewallPermCheck(Request $request, int $id)
    {
        $server = $this->checkAccess($request, $id);
        if (!$server) return response()->json(['success' => false, 'error' => 'Not found or access denied.'], 404);
        $user = $this->getUser($request);
        if (!$this->checkSubuserPerm($server, $user, 'firewall')) {
            return response()->json(['success' => false, 'error' => 'No permission.'], 403);
        }
        return $server;
    }

    public function firewallIndex(Request $request, int $id): JsonResponse
    {
        $server = $this->firewallPermCheck($request, $id);
        if ($server instanceof JsonResponse) return $server;
        $rules = FirewallRule::where('server_id', $server->id)->orderBy('priority')->orderBy('id')->get();
        return response()->json(['success' => true, 'rules' => $rules]);
    }

    public function firewallStore(Request $request, int $id): JsonResponse
    {
        $server = $this->firewallPermCheck($request, $id);
        if ($server instanceof JsonResponse) return $server;
        $data = $request->validate([
            'port' => 'nullable|integer|min:1|max:65535',
            'protocol' => 'required|in:tcp,udp,icmp,any',
            'sources' => 'nullable|string|max:1000',
            'action' => 'required|in:allow,deny',
            'priority' => 'nullable|integer',
            'is_enabled' => 'nullable|boolean',
        ]);
        $data['server_id'] = $server->id;
        $data['sources'] = $data['sources'] ?? null;
        $data['priority'] = $data['priority'] ?? 0;
        $data['is_enabled'] = $data['is_enabled'] ?? true;
        $rule = FirewallRule::create($data);
        ActivityLog::create([
            'server_id' => $server->id, 'user_id' => $this->getUser($request)->id,
            'action' => 'server:firewall:create', 'metadata' => json_encode($rule->toArray()),
            'ip_address' => $request->ip(),
        ]);
        return response()->json(['success' => true, 'rule' => $rule]);
    }

    public function firewallUpdate(Request $request, int $id, FirewallRule $rule): JsonResponse
    {
        $server = $this->firewallPermCheck($request, $id);
        if ($server instanceof JsonResponse) return $server;
        if ($rule->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Rule not found.'], 404);
        $data = $request->validate([
            'port' => 'nullable|integer|min:1|max:65535',
            'protocol' => 'sometimes|in:tcp,udp,icmp,any',
            'sources' => 'nullable|string|max:1000',
            'action' => 'sometimes|in:allow,deny',
            'priority' => 'nullable|integer',
            'is_enabled' => 'nullable|boolean',
        ]);
        $rule->update($data);
        return response()->json(['success' => true, 'rule' => $rule]);
    }

    public function firewallDestroy(Request $request, int $id, FirewallRule $rule): JsonResponse
    {
        $server = $this->firewallPermCheck($request, $id);
        if ($server instanceof JsonResponse) return $server;
        if ($rule->server_id !== $server->id) return response()->json(['success' => false, 'error' => 'Rule not found.'], 404);
        $rule->delete();
        ActivityLog::create([
            'server_id' => $server->id, 'user_id' => $this->getUser($request)->id,
            'action' => 'server:firewall:destroy', 'metadata' => json_encode(['id' => $rule->id]),
            'ip_address' => $request->ip(),
        ]);
        return response()->json(['success' => true]);
    }

    // ── Live logs website ──
    public function logsPage(Request $request, int $id)
    {
        $user = $this->getUser($request);
        if (!$user) return redirect('/auth/login?redirect=' . urlencode($request->fullUrl()));
        $server = $this->getServer($request, $id);
        if (!$server) abort(404);
        if (!$this->checkSubuserPerm($server, $user, 'console')) abort(403);
        return view('logs', [
            'server' => $server,
            'panelName' => Setting::get('panel:name', 'DragoraPanel'),
        ]);
    }

    // ── Helpers ──

    /**
     * Files that are hidden from normal users and cannot be read, written,
     * renamed, uploaded over, or deleted by anyone but admins.
     */
    private function isProtectedFileName(string $name): bool
    {
        $lower = strtolower($name);
        if (in_array($lower, ['eula.txt', 'server.properties', 'egg.json'], true)) return true;
        return str_starts_with($lower, 'minecraft-server-') && str_ends_with($lower, '.jar');
    }

    private function denyProtectedFile(User $user): ?JsonResponse
    {
        if ($user->isAdmin()) return null;
        return response()->json(['success' => false, 'error' => 'This file is protected.'], 403);
    }

    private function dirContainsProtectedFile(string $dir): bool
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                if ($this->dirContainsProtectedFile($path)) return true;
            } elseif ($this->isProtectedFileName($item)) {
                return true;
            }
        }
        return false;
    }

    private function resolvePath(string $root, string $path): ?string
    {
        $rootReal = realpath($root);
        if ($rootReal !== false) $root = $rootReal;
        $root = rtrim($root, '\\/');
        $path = str_replace(['../', '..\\'], '', $path);
        $full = realpath($root . DIRECTORY_SEPARATOR . ltrim($path, '\\/'));
        if ($full === false || !str_starts_with($full, $root)) return null;
        return $full;
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
