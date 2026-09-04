<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\AchievementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One medal a user has earned.
 *
 * A medal records that something happened, so a row is written once and never
 * revoked: the streak that earned it can break and the balance that earned it
 * can fall, and the row stays. `achieved_on` is the first day of the month it
 * really happened — the history can date a milestone to a month, never to a day.
 *
 * Which column holds the figure reached says how to read it: `value` with a
 * `currency_code` is money in minor units, `percent` is a rate, and `value`
 * alone is a plain count of transactions or months.
 *
 * @property string $key
 * @property Carbon $achieved_on
 * @property ?int $value
 * @property ?float $percent
 * @property ?string $currency_code
 */
class Achievement extends Model
{
    /** @use HasFactory<AchievementFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'key',
        'achieved_on',
        'value',
        'percent',
        'currency_code',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'achieved_on' => 'date:Y-m-d',
            'value' => 'integer',
            'percent' => 'float',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
