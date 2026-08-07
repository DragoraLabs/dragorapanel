<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\Server;
use App\Services\RedisBroker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AgentListenCommand extends Command
{
    protected $signature = 'agent:listen';
    protected $description = 'Listen for agent messages from Redis pub/sub';

    public function handle(RedisBroker $broker): void
    {
        $this->info('Starting Redis listener for agent messages...');
        $this->info('Press Ctrl+C to stop.');

        $broker->subscribe(
            onStatus: function (string $channel, ?array $data) {
                if (!$data || !isset($data['server_id'])) return;
                $serverId = $data['server_id'];

                try {
                    Server::where('id', $serverId)->update([
                        'status' => $data['status'] ?? 'offline',
                        'memory_used_mb' => $data['memory_used_mb'] ?? 0,
                        'cpu_percent' => $data['cpu_percent'] ?? 0,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to update status for server {$serverId}: {$e->getMessage()}");
                }
            },
            onReport: function (string $channel, ?array $data) {
                if (!$data || !isset($data['node_id'])) return;
                $nodeId = $data['node_id'];

                try {
                    Node::where('id', $nodeId)->update([
                        'cpu_percent' => $data['cpu_percent'] ?? 0,
                        'memory_used_mb' => $data['memory_used_mb'] ?? 0,
                        'disk_used_mb' => $data['disk_used_mb'] ?? 0,
                        'cpu_cores' => $data['cpu_cores'] ?? 0,
                        'status' => 'online',
                        'last_seen_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Failed to update node {$nodeId} report: {$e->getMessage()}");
                }
            }
        );
    }
}
