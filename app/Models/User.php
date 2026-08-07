<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'email', 'password', 'first_name', 'last_name', 'role', 'banned',
        'email_verified_at', 'language', 'timezone',
        'username', 'avatar', 'bio', 'theme', 'two_fa_secret', 'two_fa_enabled',
    ];

    protected $hidden = ['password', 'remember_token', 'two_fa_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_fa_enabled' => 'boolean',
        ];
    }

    public function servers() { return $this->hasMany(Server::class); }
    public function sessions() { return $this->hasMany(Session::class); }
    public function subusers() { return $this->hasMany(Subuser::class); }
    public function apiTokens() { return $this->hasMany(ApiToken::class); }
    public function notifications() { return $this->hasMany(Notification::class); }
    public function activityLogs() { return $this->hasMany(ActivityLog::class); }

    public function organizations() { return $this->belongsToMany(Organization::class, 'organization_members')->withPivot('role'); }
    public function ownedOrganizations() { return $this->hasMany(Organization::class, 'owner_id'); }

    public function isAdmin(): bool { return $this->role === 'admin'; }

    public function isBanned(): bool { return (bool) $this->banned; }

    public function displayName(): string
    {
        if (!empty($this->username)) return $this->username;
        return trim($this->first_name . ' ' . $this->last_name) ?: $this->email;
    }

    public function twoFactorEnabled(): bool { return $this->two_fa_enabled; }
}
