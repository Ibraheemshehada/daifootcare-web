<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfCareLog extends Model
{
    protected $fillable = ['local_uuid','patient_id','item_key','log_date','done_at','synced_at'];

    protected function casts(): array
    {
        return ['log_date'=>'date','done_at'=>'datetime','synced_at'=>'datetime'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
