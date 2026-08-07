<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerDatabase extends Model
{
    protected $fillable = ['server_id', 'database_host_id', 'database_name', 'username', 'remote_host', 'password'];

    protected $hidden = ['password'];

    public function server() { return $this->belongsTo(Server::class); }

    public function host() { return $this->belongsTo(DatabaseHost::class, 'database_host_id'); }
}
