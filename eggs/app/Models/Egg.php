<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Egg extends Model
{
    protected $fillable = [
        'uuid', 'name', 'description', 'author', 'type',
        'docker_image', 'startup_command', 'config_files',
        'default_version', 'java_version', 'supported_versions', 'is_active',
    ];

    protected $casts = [
        'config_files' => 'array',
        'supported_versions' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Egg $egg) {
            if (!$egg->uuid) {
                $egg->uuid = (string) Str::uuid();
            }
        });
    }

    public function variables(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EggVariable::class)->orderBy('sort_order');
    }

    public function servers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Server::class);
    }
}
