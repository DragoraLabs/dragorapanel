<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    protected $fillable = ['server_id', 'name', 'file_hash', 'size_bytes', 'status', 'restore_status', 'is_locked'];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'restored_at' => 'datetime',
        ];
    }

    public function server() { return $this->belongsTo(Server::class); }

    public function isSuccessful(): bool { return $this->status === 'completed'; }
    public function isFailed(): bool { return $this->status === 'failed'; }
}
