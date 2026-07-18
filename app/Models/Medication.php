<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Medication extends Model
{
    protected $fillable = ['local_uuid','patient_id','name','dosage','times_per_day','is_active','synced_at'];

    protected function casts(): array
    {
        return ['is_active'=>'boolean','synced_at'=>'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
