<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirewallRule extends Model
{
    protected $fillable = [
        'server_id', 'port', 'protocol', 'sources', 'action', 'priority', 'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'port' => 'integer',
        'priority' => 'integer',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function getSourceList(): array
    {
        if (!$this->sources) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $this->sources))));
    }
}
