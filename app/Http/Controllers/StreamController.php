<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Session;
use App\Models\Subuser;
use App\Models\User;
use App\Services\ApiAuth;
use App\Services\RedisBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class StreamController extends Controller
{
    private function getUser(Request $request): ?User
    {
        return ApiAuth::user($request);
    }

    private function checkAccess(Server $server, User $user): bool
    {
        if ($user->isAdmin()) return true;
        if ($server->user_id === $user->id) return true;
        $subuser = Subuser::where('user_id', $user->id)->where('server_id', $server->id)->first();
        return $subuser && $subuser->hasPermission('console');
    }

    public function console(int $id, Request $request)
    {
        $user = $this->getUser($request);
        if (!$user) {
            return response()->json(['error' => 'Not authenticated'], 401);
        }

        $server = Server::find($id);
        if (!$server || !$this->checkAccess($server, $user)) {
            return response()->json(['error' => 'Not found or access denied'], 403);
        }

        $broker = app(RedisBroker::class);

        // Lazy console cleanup: if the server is stopped and no one has opened
        // the console for 5+ minutes, wipe the log backlog so the next session
        // starts fresh.
        $accessKey = "server:{$id}:access";
        $lastAccess = (int) Redis::get($accessKey);
        Redis::setex($accessKey, 3600, time());
        $status = $broker->getServerStatus($id);
        $state = $status['status'] ?? null;
        if (!$state) {
            $state = DB::table('servers')->where('id', $id)->value('status');
        }
        if (in_array($state, ['offline', 'crashed', 'stopped'], true) && $lastAccess > 0 && (time() - $lastAccess) >= 300) {
            Redis::del("server:{$id}:logs");
            DB::table('servers')->where('id', $id)->update(['console_logs' => '']);
        }

        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($id) {
            $lastLineCount = 0;
            $lastStatus = '';
            $broker = app(RedisBroker::class);
            while (true) {
                if (connection_aborted()) break;

                // Redis fast path (real-time, dedup by hash)
                $redisLogs = $broker->getConsoleLogs($id, 50);
                $newLogs = [];
                foreach ($redisLogs as $log) {
                    $line = $log['line'] ?? '';
                    if ($line === '') continue;
                    $hash = md5($line . ($log['type'] ?? 'info'));
                    if (!isset($GLOBALS['seen_' . $id . '_' . $hash])) {
                        $GLOBALS['seen_' . $id . '_' . $hash] = true;
                        $newLogs[] = $log;
                    }
                }
                if (!empty($newLogs)) {
                    echo "data: " . json_encode(['type' => 'console', 'logs' => array_slice($newLogs, -10)]) . "\n\n";
                    ob_flush();
                    flush();
                }

                // Status: Redis hash first, then DB
                $status = $broker->getServerStatus($id);
                $server = null;
                if ($status && !empty($status['status'])) {
                    $server = $status;
                } else {
                    $server = DB::table('servers')->select('status', 'memory_used_mb', 'cpu_percent', 'disk_used_mb')->where('id', $id)->first();
                    if ($server) {
                        $server = ['status' => $server->status, 'memory_used' => $server->memory_used_mb, 'cpu_percent' => $server->cpu_percent, 'disk_used' => $server->disk_used_mb];
                    }
                }
                if ($server) {
                    $statusStr = ($server['status'] ?? '') . '|' . ($server['memory_used'] ?? '') . '|' . ($server['cpu_percent'] ?? '');
                    if ($statusStr !== $lastStatus) {
                        echo "data: " . json_encode(['type' => 'status', 'status' => $server]) . "\n\n";
                        ob_flush();
                        flush();
                        $lastStatus = $statusStr;
                    }
                }

                // DB log fallback (only when Redis empty)
                if (empty($redisLogs)) {
                    $raw = DB::table('servers')->where('id', $id)->value('console_logs');
                    $logs = $raw ? explode("\n", $raw) : [];
                    $total = count($logs);
                    if ($total > $lastLineCount) {
                        $newLines = array_slice($logs, -($total - $lastLineCount));
                        $formatted = array_map(fn($l) => ['line' => $l, 'type' => 'info', 'time' => time()], $newLines);
                        echo "data: " . json_encode(['type' => 'console', 'logs' => $formatted]) . "\n\n";
                        ob_flush();
                        flush();
                        $lastLineCount = $total;
                    }
                }

                sleep(1);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
