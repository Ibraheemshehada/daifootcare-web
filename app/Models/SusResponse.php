<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SusResponse extends Model
{
    protected $fillable = [
        'local_uuid', 'patient_id',
        'q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9', 'q10',
        'score', 'consent_version', 'recorded_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'synced_at' => 'datetime',
            'score' => 'float',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Official System Usability Scale scoring (Brooke, 1986).
     *
     * Odd items are positively worded and contribute `response - 1`; even items
     * are negatively worded and contribute `5 - response`. The sum (0..40) is
     * multiplied by 2.5 to give 0..100.
     *
     * Note this is **not** a percentage, and 68 is the conventional average — a
     * naive implementation that just averages the raw answers returns 100 for
     * all-5s, where the correct answer is 50.
     *
     * @param  array<string,int>  $answers  keyed q1..q10
     */
    public static function scoreFor(array $answers): float
    {
        $sum = 0;

        for ($i = 1; $i <= 10; $i++) {
            $v = (int) ($answers["q{$i}"] ?? 3);
            $sum += ($i % 2 === 1) ? ($v - 1) : (5 - $v);
        }

        return round($sum * 2.5, 2);
    }

    /** Adjective band for a score, matching the app's bands. */
    public function band(): string
    {
        return match (true) {
            $this->score >= 85 => 'excellent',
            $this->score >= 68 => 'good',
            $this->score >= 51 => 'ok',
            default => 'poor',
        };
    }
}
