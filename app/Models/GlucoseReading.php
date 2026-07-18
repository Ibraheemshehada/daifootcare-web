<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlucoseReading extends Model
{
    protected $fillable = ['local_uuid','patient_id','device_id','value_mgdl','tag','measured_at','synced_at'];

    protected function casts(): array
    {
        return ['measured_at'=>'datetime','synced_at'=>'datetime','value_mgdl'=>'float'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
