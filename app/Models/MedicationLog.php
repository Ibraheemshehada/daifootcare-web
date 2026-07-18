<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationLog extends Model
{
    protected $fillable = ['local_uuid','patient_id','medication_id','medication_local_uuid','log_date','dose_index','taken','synced_at'];

    protected function casts(): array
    {
        return ['log_date'=>'date','taken'=>'boolean','synced_at'=>'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
