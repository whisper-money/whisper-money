<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CreditCardDetailFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The statement dates of a credit card: one closing date and the date that
 * statement is charged. They are an anchor, not a schedule. Later cycles are
 * projected a month at a time from them, and when the bank moves the dates the
 * user edits them, which sets a new anchor.
 *
 * @property CarbonImmutable $statement_closing_date
 * @property CarbonImmutable $payment_due_date
 */
class CreditCardDetail extends Model
{
    /** @use HasFactory<CreditCardDetailFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'statement_closing_date',
        'payment_due_date',
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
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
