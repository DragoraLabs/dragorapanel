<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Server extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'egg_id', 'user_id', 'node_id', 'name', 'type', 'version', 'java_version', 'docker_image', 'status',
        'memory_mb', 'storage_mb', 'port', 'ip_address',
        'console_logs', 'command_queue', 'memory_used_mb', 'cpu_percent',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function node() { return $this->belongsTo(Node::class); }
    public function egg() { return $this->belongsTo(Egg::class); }
    public function databases() { return $this->hasMany(ServerDatabase::class); }
    public function backups() { return $this->hasMany(Backup::class); }
    public function schedules() { return $this->hasMany(Schedule::class); }
    public function subusers() { return $this->hasMany(Subuser::class); }
    public function allocations() { return $this->hasMany(Allocation::class); }
    public function activityLogs() { return $this->hasMany(ActivityLog::class); }

    public function appendLog(string $line): void
    {
        $quoted = DB::connection()->getPdo()->quote($line);
        DB::statement("UPDATE servers SET console_logs = CONCAT(IFNULL(console_logs, ''), '\n', $quoted) WHERE id = ?", [$this->id]);
        // Trim to last 500 lines periodically (every 50 lines)
        DB::statement("UPDATE servers SET console_logs = SUBSTRING_INDEX(console_logs, '\n', -500) WHERE id = ? AND (LENGTH(console_logs) - LENGTH(REPLACE(console_logs, '\n', ''))) > 500", [$this->id]);
    }

    public function getLogs(): array
    {
        $raw = $this->console_logs;
        if (!$raw) return [];
        $lines = explode("\n", $raw);
        return array_slice($lines, -200);
    }

    public function queueCommand(string $command): void
    {
        $queue = $this->command_queue ? json_decode($this->command_queue, true) : [];
        $queue[] = $command;
        $this->update(['command_queue' => json_encode($queue)]);
    }

    public function shiftCommands(): array
    {
        $cmds = $this->command_queue ? json_decode($this->command_queue, true) : [];
        $this->update(['command_queue' => null]);
        return $cmds;
    }
}
