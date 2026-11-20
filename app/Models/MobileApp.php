<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MobileApp extends Model
{
    protected $table = 'apps';

    protected $guarded = [];

    protected $casts = [
        'force_update' => 'boolean',
        'min_version_code' => 'integer',
    ];

    public function releases(): HasMany
    {
        return $this->hasMany(AppRelease::class, 'app_id')->orderByDesc('version_code');
    }

    /**
     * A release actual é a de maior version_code, não a mais recente por data.
     */
    public function latestRelease(string $platform = 'android'): ?AppRelease
    {
        return $this->releases()->where('platform', $platform)->first();
    }

    public function toAdminArray(): array
    {
        $latest = $this->latestRelease();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'package_name' => $this->package_name,
            'min_version_code' => (int) $this->min_version_code,
            'force_update' => (bool) $this->force_update,
            'latest_release' => $latest?->toApiArray(),
        ];
    }
}
