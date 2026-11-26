<?php

namespace App\Models;

use App\Enums\ForecastAlertLevel;
use App\Models\Concerns\BelongsToSpace;
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
        ];
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
