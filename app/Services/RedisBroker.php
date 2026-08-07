<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RedisBroker
{
    // ── Publish power action to agent ──

    public function publishPower(int $nodeId, int $serverId, string $action): bool
    {
        try {
            $channel = "node:{$nodeId}:server:{$serverId}:power";
            Redis::publish($channel, json_encode([
                'server_id' => $serverId,
                'action' => $action,
            ]));
            return true;
        } catch (\Throwable $e) {
            Log::debug("[RedisBroker] publishPower failed: {$e->getMessage()}");
            return false;
        }
    }

    // ── Publish command to agent ──

    public function publishCommand(int $nodeId, int $serverId, string $command): bool
    {
        try {
            $channel = "node:{$nodeId}:server:{$serverId}:commands";
            Redis::publish($channel, json_encode([
                'server_id' => $serverId,
                'command' => $command,
            ]));
            return true;
        } catch (\Throwable $e) {
            Log::debug("[RedisBroker] publishCommand failed: {$e->getMessage()}");
            return false;
        }
    }

    // ── Publish reinstall action to agent ──

    public function publishReinstall(int $nodeId, int $serverId): bool
    {
        try {
            $channel = "node:{$nodeId}:server:{$serverId}:reinstall";
            Redis::publish($channel, json_encode([
                'server_id' => $serverId,
            ]));
            return true;
        } catch (\Throwable $e) {
            Log::debug("[RedisBroker] publishReinstall failed: {$e->getMessage()}");
            return false;
        }
    }

    // ── Publish backup request to agent ──

    public function publishBackup(int $nodeId, int $serverId, int $backupId): bool
    {
        try {
            $channel = "node:{$nodeId}:server:{$serverId}:backup";
            Redis::publish($channel, json_encode([
                'server_id' => $serverId,
                'backup_id' => $backupId,
            ]));
            return true;
        } catch (\Throwable $e) {
            Log::debug("[RedisBroker] publishBackup failed: {$e->getMessage()}");
            return false;
        }
    }

    // ── Publish restore request to agent ──

    public function publishRestore(int $nodeId, int $serverId, int $backupId): bool
    {
        try {
            $channel = "node:{$nodeId}:server:{$serverId}:restore";
            Redis::publish($channel, json_encode([
                'server_id' => $serverId,
                'backup_id' => $backupId,
            ]));
            return true;
        } catch (\Throwable $e) {
            Log::debug("[RedisBroker] publishRestore failed: {$e->getMessage()}");
            return false;
        }
    }

    // ── Get recent console logs from Redis ──

    public function getConsoleLogs(int $serverId, int $limit = 200): array
    {
        try {
            $key = "server:{$serverId}:logs";
            $raw = Redis::lrange($key, 0, $limit - 1);
            $lines = [];
            foreach ($raw as $item) {
                $decoded = json_decode($item, true);
                if ($decoded) {
                    $lines[] = $decoded;
                } else {
                    $lines[] = ['line' => $item, 'type' => 'info', 'time' => time()];
                }
            }
            return $lines;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Get server current status from Redis ──

    public function getServerStatus(int $serverId): ?array
    {
        try {
            $key = "server:{$serverId}:current";
            $data = Redis::hgetall($key);
            return $data ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Get node state from Redis ──

    public function getNodeState(int $nodeId): ?array
    {
        try {
            $key = "node:{$nodeId}:state";
            $data = Redis::hgetall($key);
            return $data ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── Check if node is alive via Redis heartbeat ──

    public function isNodeAlive(int $nodeId): bool
    {
        try {
            $key = "heartbeat:{$nodeId}";
            return (bool) Redis::exists($key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── Subscribe to agent channels (called from Artisan command) ──

    public function subscribe(callable $onConsole, callable $onStatus, callable $onReport): void
    {
        Redis::psubscribe(['node:*:server:*:console', 'node:*:server:*:status', 'node:*:report'], function ($message, $channel) use ($onConsole, $onStatus, $onReport) {
            try {
                if (str_contains($channel, ':console')) {
                    $onConsole($channel, json_decode($message, true));
                } elseif (str_contains($channel, ':status')) {
                    $onStatus($channel, json_decode($message, true));
                } elseif (str_contains($channel, ':report')) {
                    $onReport($channel, json_decode($message, true));
                }
            } catch (\Throwable $e) {
                Log::error("[RedisBroker] Error processing message from {$channel}: {$e->getMessage()}");
            }
        });
    }

    // ── Store console log to Redis cache ──

    public function pushConsoleLog(int $serverId, array $entry): void
    {
        try {
            $key = "server:{$serverId}:logs";
            Redis::lpush($key, json_encode($entry));
            Redis::ltrim($key, 0, 999);
        } catch (\Throwable $e) {
        }
    }
}
