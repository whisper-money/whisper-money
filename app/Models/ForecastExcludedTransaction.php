<?php

namespace App\Models;

use App\Enums\ForecastExclusionReason;
use Database\Factories\ForecastExcludedTransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A transaction left out of the estimated spend, because the user said it was
 * a one-off.
 *
 * @property string $id
 * @property string $transaction_id
 * @property ForecastExclusionReason $reason
 */
class ForecastExcludedTransaction extends Model
{
    /** @use HasFactory<ForecastExcludedTransactionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'transaction_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'reason' => ForecastExclusionReason::class,
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
