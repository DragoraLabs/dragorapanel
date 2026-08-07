<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplacePlugin extends Model
{
    protected $fillable = [
        'user_id', 'unique_id', 'name', 'version', 'description', 'license',
        'status', 'icon', 'zip_file', 'hooks', 'downloads',
        'reject_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'hooks' => 'array',
            'downloads' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPublished(): bool
    {
        return $this->status === 'approved';
    }

    /** Absolute path to the uploaded plugin zip. */
    public function zipPath(): ?string
    {
        return $this->zip_file
            ? storage_path('app/marketplace/' . $this->unique_id . '/' . basename($this->zip_file))
            : null;
    }

    /** Absolute filesystem path of this plugin's extracted asset dir. */
    public function assetDir(): string
    {
        return storage_path('app/marketplace/' . $this->unique_id);
    }

    public function iconUrl(): string
    {
        if (str_starts_with($this->icon, 'http') || str_starts_with($this->icon, '/')) {
            return $this->icon;
        }
        // Look for an uploaded icon image in the asset dir.
        $glob = glob($this->assetDir() . '/icon.*');
        if ($glob) {
            return route('store.raw', ['id' => $this->id, 'path' => basename($glob[0])]);
        }
        return '#';
    }
}