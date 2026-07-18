<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    public const MODE_ONLINE = 'online';
    public const MODE_OFFLINE = 'offline';

    protected $fillable = [
        'user_id',
        'device_uuid',
        'platform',
        'app_version',
        'mode',
        'models_downloaded_at',
        'models_version',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'models_downloaded_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function woundScans(): HasMany
    {
        return $this->hasMany(WoundScan::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    public function touchLastSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->save();
    }
}
