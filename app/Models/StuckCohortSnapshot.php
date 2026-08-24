<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property Carbon $date
 * @property int $onboarded_count
 * @property int $stuck_count
 * @property float $stuck_pct
 */
class StuckCohortSnapshot extends Model
{
    protected $fillable = [
        'date',
        'onboarded_count',
        'stuck_count',
        'stuck_pct',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'onboarded_count' => 'integer',
            'stuck_count' => 'integer',
            'stuck_pct' => 'float',
        ];
    }
}
