<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Node extends Model
{
    protected $fillable = [
        'name', 'fqdn', 'ip_address', 'port', 'location_id', 'token',
        'memory_mb', 'storage_mb', 'cpu_cores',
        'disk_used_mb', 'memory_used_mb', 'cpu_percent',
        'status', 'last_seen_at',
        'uuid', 'api_host', 'api_port', 'tls_enabled', 'tls_cert', 'tls_key',
        'storage_servers', 'storage_backups', 'runtime_engine', 'runtime_network',
        'features', 'limits_cpu', 'limits_disk', 'plugins_repository',
        'security_verify_tls', 'security_mounts', 'cluster_enabled',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'tls_enabled' => 'boolean',
        'security_verify_tls' => 'boolean',
        'cluster_enabled' => 'boolean',
        'features' => 'array',
        'security_mounts' => 'array',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    public function allocations()
    {
        return $this->hasMany(Allocation::class);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }
}
