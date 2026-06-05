<?php

namespace App\Models;

use Database\Factories\LoanDetailFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property float $annual_interest_rate
 * @property int $loan_term_months
 * @property Carbon $start_date
 * @property int $original_amount
 */
class LoanDetail extends Model
{
    /** @use HasFactory<LoanDetailFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'annual_interest_rate',
        'loan_term_months',
        'start_date',
        'original_amount',
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
            'annual_interest_rate' => 'decimal:3',
            'loan_term_months' => 'integer',
            'start_date' => 'date',
            'original_amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
