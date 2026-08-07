<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EggVariable extends Model
{
    protected $fillable = [
        'egg_id', 'name', 'description', 'env_variable',
        'default_value', 'rules', 'is_required',
        'user_viewable', 'user_editable', 'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'user_viewable' => 'boolean',
        'user_editable' => 'boolean',
    ];

    public function egg(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Egg::class);
    }
}
