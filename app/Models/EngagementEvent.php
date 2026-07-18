<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngagementEvent extends Model
{
    protected $fillable = ['local_uuid','patient_id','device_id','name','target','value','occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at'=>'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
