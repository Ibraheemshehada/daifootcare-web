<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day's worth of one engagement metric for one patient.
 *
 * Counts are **absolute, not incremental** — the device sends the running total
 * for that day and the row is overwritten. Adding deltas would double-count
 * every time a day was re-sent, which happens routinely: a day's rollup is
 * uploaded repeatedly as it grows.
 */
class EngagementDaily extends Model
{
    protected $table = 'engagement_daily';

    protected $fillable = [
        'local_uuid', 'patient_id', 'device_id',
        'day', 'name', 'target', 'event_count', 'total_value',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'event_count' => 'integer',
            'total_value' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** Mean payload per event — dwell milliseconds for screen_close. */
    public function getMeanValueAttribute(): ?float
    {
        if (! $this->total_value || $this->event_count === 0) return null;

        return round($this->total_value / $this->event_count, 1);
    }
}
