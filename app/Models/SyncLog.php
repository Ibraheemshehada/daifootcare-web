<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $fillable = [
        'device_id',
        'batch_uuid',
        'records_count',
        'synced_count',
        'failed_count',
        'status',
        'error_message',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
