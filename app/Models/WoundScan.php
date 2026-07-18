<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WoundScan extends Model
{
    protected $fillable = [
        'local_uuid',
        'patient_id',
        'device_id',
        'captured_at',
        'length_cm',
        'width_cm',
        'area_cm2',
        'depth_cm',
        'is_calibrated',
        'tissue_json',
        'infection_present',
        'infection_prob',
        'ischaemia_present',
        'ischaemia_prob',
        'risk_badge',
        'image_path',
        'models_version',
        'source',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'synced_at' => 'datetime',
            'is_calibrated' => 'boolean',
            'infection_present' => 'boolean',
            'ischaemia_present' => 'boolean',
            'tissue_json' => 'array',
            'length_cm' => 'float',
            'width_cm' => 'float',
            'area_cm2' => 'float',
            'depth_cm' => 'float',
            'infection_prob' => 'float',
            'ischaemia_prob' => 'float',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
