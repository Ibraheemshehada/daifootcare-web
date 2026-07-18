<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SatisfactionEntry extends Model
{
    protected $fillable = ['local_uuid','patient_id','ease_of_use','usefulness','would_continue','recorded_at','synced_at'];

    protected function casts(): array
    {
        return ['recorded_at'=>'datetime','synced_at'=>'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
