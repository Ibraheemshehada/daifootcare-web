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

    /**
     * Appended so every endpoint returning a scan carries the derived tissue
     * fields, and no client has to reimplement the severity rule.
     */
    protected $appends = [
        'primary_tissue_type',
        'tissue_summary',
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

    /**
     * Tissue classes in descending clinical seriousness.
     *
     * Must match TissueFinding::severityOrder in the app and TISSUE_SEVERITY in
     * inference/pipeline.py. Necrotic and sloughy tissue are devitalised and
     * drive debridement decisions, so they lead; callus sits below granulation
     * because a bed that is mostly granulating should not be headlined as
     * callus for scraping over a low threshold.
     */
    public const TISSUE_SEVERITY = [
        'necrosis', 'slough', 'granulation', 'callus', 'epithelial',
    ];

    /**
     * The per-class findings, or an empty array.
     *
     * `tissue_json` holds `{ label, findings[] }`. Scans synced before the app
     * reported per-class results carry only the label, so this is empty for
     * them rather than fabricated — the probabilities were never recorded.
     */
    public function getTissueFindingsAttribute(): array
    {
        return $this->tissue_json['findings'] ?? [];
    }

    /** Every class that cleared its own threshold, most serious first. */
    public function getPresentTissuesAttribute(): array
    {
        $present = array_values(array_filter(
            $this->tissue_findings,
            fn ($f) => ($f['is_present'] ?? false) === true
        ));

        usort($present, function ($a, $b) {
            $ia = array_search($a['type'] ?? '', self::TISSUE_SEVERITY, true);
            $ib = array_search($b['type'] ?? '', self::TISSUE_SEVERITY, true);
            $ia = $ia === false ? PHP_INT_MAX : $ia;
            $ib = $ib === false ? PHP_INT_MAX : $ib;

            return $ia === $ib
                ? ($b['probability'] ?? 0) <=> ($a['probability'] ?? 0)
                : $ia <=> $ib;
        });

        return $present;
    }

    /**
     * The single label to show where only one fits: the most clinically serious
     * class present.
     *
     * Derived here rather than trusted from the payload so the dashboard and
     * the phone cannot drift apart, and so scans that predate findings still
     * resolve through their stored label.
     */
    public function getPrimaryTissueTypeAttribute(): ?string
    {
        $present = $this->present_tissues;

        if ($present !== []) {
            return ucfirst($present[0]['type']);
        }

        $label = $this->tissue_json['label'] ?? null;

        return $label ? ucfirst($label) : null;
    }

    /** "Necrosis, Slough, Callus" — every tissue found, most serious first. */
    public function getTissueSummaryAttribute(): ?string
    {
        $present = $this->present_tissues;

        if ($present === []) {
            return $this->primary_tissue_type;
        }

        return implode(', ', array_map(fn ($f) => ucfirst($f['type']), $present));
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
