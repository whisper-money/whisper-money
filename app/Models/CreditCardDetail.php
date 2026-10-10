<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CreditCardDetailFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the user told us about a credit card: its credit limit and its
 * statement dates, each optional.
 *
 * The dates are one closing date and the date that statement is charged. They
 * are an anchor, not a schedule. Later cycles are projected a month at a time
 * from them, and when the bank moves the dates the user edits them, which sets
 * a new anchor. They travel together: both set or both null.
 *
 * @property CarbonImmutable|null $statement_closing_date
 * @property CarbonImmutable|null $payment_due_date
 * @property int|null $credit_limit in the card currency's minor units
 */
class CreditCardDetail extends Model
{
    /** @use HasFactory<CreditCardDetailFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'statement_closing_date',
        'payment_due_date',
        'credit_limit',
    ];

    /** @var list<string> */
    protected $hidden = [
        'account_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_closing_date' => 'immutable_date:Y-m-d',
            'payment_due_date' => 'immutable_date:Y-m-d',
            'credit_limit' => 'integer',
        ];
    }

    public function hasStatementDates(): bool
    {
        return $this->statement_closing_date !== null && $this->payment_due_date !== null;
    }

    /**
     * Nothing left worth keeping a row for.
     */
    public function isBlank(): bool
    {
        return ! $this->hasStatementDates() && $this->credit_limit === null;
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
