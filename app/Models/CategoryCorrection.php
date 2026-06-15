<?php

namespace App\Models;

use App\Enums\CategorySource;
use Database\Factories\CategoryCorrectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records every time a user overrides a category that was assigned by AI (either
 * directly or via an AI-owned rule). The signal is used to measure accuracy per
 * confidence bucket and to recalibrate the auto-apply thresholds.
 */
class CategoryCorrection extends Model
{
    /** @use HasFactory<CategoryCorrectionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'transaction_id',
        'from_category_id',
        'to_category_id',
        'source',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'source' => CategorySource::class,
            'confidence' => 'float',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
