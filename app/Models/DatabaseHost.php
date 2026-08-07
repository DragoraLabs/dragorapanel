<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DatabaseHost extends Model
{
    protected $fillable = [
        'name', 'host', 'port', 'username', 'password', 'max_databases', 'is_enabled',
    ];

    protected $casts = [
        'port' => 'integer',
        'max_databases' => 'integer',
        'is_enabled' => 'boolean',
    ];

    protected $hidden = ['password'];

    public function pdo(): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->host, $this->port);
        return new \PDO($dsn, $this->username, $this->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public function currentDatabaseCount(): int
    {
        return \App\Models\ServerDatabase::where('database_host_id', $this->id)->count();
    }
}
