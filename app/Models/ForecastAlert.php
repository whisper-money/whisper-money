<?php

namespace App\Models;

use App\Enums\ForecastAlertLevel;
use App\Models\Concerns\BelongsToSpace;
use App\Services\Forecast\Data\ForecastAlertFinding;
use Carbon\Carbon;
use Database\Factories\ForecastAlertFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A low-balance problem the forecast found in one account's month. It lives on
 * so the email about it goes out once, until it is resolved.
 *
 * @property string $id
 * @property string $user_id
 * @property string $account_id
 * @property Carbon $month
 * @property ForecastAlertLevel $level
 * @property Carbon $first_seen_at
 * @property Carbon|null $notified_at
 * @property Carbon|null $resolved_at
 * @property int|null $lowest_balance The strong month's lowest balance, in the account's minor units.
 * @property Carbon|null $lowest_on The day that lowest balance falls on.
 * @property list<array{name: string, amount: int}>|null $causes The charges behind the dip, largest first.
 * @property Carbon|null $snoozed_until The dashboard card stays hidden until this day.
 */
class ForecastAlert extends Model
{
    /** @use HasFactory<ForecastAlertFactory> */
    use BelongsToSpace, HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'account_id',
        'month',
        'level',
        'first_seen_at',
        'notified_at',
        'resolved_at',
        'lowest_balance',
        'lowest_on',
        'causes',
        'snoozed_until',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'level' => ForecastAlertLevel::class,
            'first_seen_at' => 'datetime',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
            'lowest_balance' => 'integer',
            'lowest_on' => 'date:Y-m-d',
            'causes' => 'array',
            'snoozed_until' => 'date:Y-m-d',
        ];
    }

    /**
     * The key the forecast's findings are matched to stored alerts by.
     */
    public function key(): string
    {
        return ForecastAlertFinding::keyFor($this->account_id, $this->month->format('Y-m'));
    }

    /**
     * What the alert keeps of a finding for the dashboard's card.
     *
     * @return array{lowest_balance: int|null, lowest_on: string|null, causes: list<array{name: string, amount: int}>}
     */
    public static function detailsFrom(ForecastAlertFinding $finding): array
    {
        return ['lowest_balance' => $finding->lowestBalance, 'lowest_on' => $finding->lowestOn, 'causes' => $finding->causes];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
