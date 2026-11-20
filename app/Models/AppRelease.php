<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppRelease extends Model
{
    public const DISK = 'local';

    protected $guarded = [];

    protected $casts = [
        'version_code' => 'integer',
        'file_size' => 'integer',
        'force_update' => 'boolean',
    ];

    public function app(): BelongsTo
    {
        return $this->belongsTo(MobileApp::class, 'app_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * URL público e sem token: é aberto pelo browser do telemóvel, fora da app.
     */
    public function downloadUrl(): string
    {
        return url("api/apps/{$this->app->slug}/download/{$this->version_code}.apk");
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'version_name' => $this->version_name,
            'version_code' => (int) $this->version_code,
            'file_size' => $this->file_size,
            'changelog' => $this->changelog,
            'force_update' => (bool) $this->force_update,
            'published_by' => $this->publisher?->name,
            'download_url' => $this->downloadUrl(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
