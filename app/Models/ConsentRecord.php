<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentRecord extends Model
{
    protected $fillable = ['local_uuid','patient_id','version','accepted_at','locale','covers_prior'];

    protected function casts(): array
    {
        return ['accepted_at'=>'datetime','covers_prior'=>'boolean'];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }
}
