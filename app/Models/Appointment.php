<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = ['local_uuid','patient_id','title','scheduled_at','location','notes','synced_at'];

    protected function casts(): array
    {
        return ['scheduled_at'=>'datetime','synced_at'=>'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
