<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $fillable = ['name', 'slug', 'invite_code', 'description', 'owner_id'];

    protected function casts(): array
    {
        return ['owner_id' => 'int'];
    }

    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function members() { return $this->belongsToMany(User::class, 'organization_members')->withPivot('role'); }
}
