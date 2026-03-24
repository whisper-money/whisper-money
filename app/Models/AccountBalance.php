<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\AccountBalanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property Carbon $balance_date
 */
class AccountBalance extends Model
{
    /** @use HasFactory<AccountBalanceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'balance_date',
        'balance',
        'invested_amount',
    ];

    protected function casts(): array
    {
        return [
            'balance_date' => 'date',
            'balance' => 'integer',
            'invested_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
