<?php

namespace App\Models;

use Database\Factories\ForecastAccountSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one account takes part in the forecast. It hangs off the account, which
 * already carries the owner and the space.
 *
 * @property string $id
 * @property string $account_id
 * @property bool $included
 * @property int|null $minimum_balance The balance the user wants to keep, in minor units.
 * @property int|null $annual_rate_bps Yearly growth of an investment or property, in basis points.
 */
class ForecastAccountSetting extends Model
{
    /** @use HasFactory<ForecastAccountSettingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'included',
        'minimum_balance',
        'annual_rate_bps',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'included' => true,
    ];

    protected function casts(): array
    {
        return [
            'included' => 'boolean',
            'minimum_balance' => 'integer',
            'annual_rate_bps' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
