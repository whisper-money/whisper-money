<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Services\CategoryTree;
use Carbon\Carbon;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property Carbon $transaction_date
 * @property int|float $total_amount
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => TransactionCreated::class,
        'updated' => TransactionUpdated::class,
        'deleted' => TransactionDeleted::class,
    ];

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'description',
        'description_iv',
        'original_description',
        'transaction_date',
        'amount',
        'currency_code',
        'notes',
        'notes_iv',
        'source',
        'external_transaction_id',
        'dedup_fingerprint',
        'raw_data',
        'creditor_name',
        'debtor_name',
    ];

    /**
     * Internal columns that must never reach the frontend (raw bank payloads,
     * dedup metadata and the pre-formatting description).
     *
     * @var list<string>
     */
    protected $hidden = [
        'original_description',
        'external_transaction_id',
        'dedup_fingerprint',
        'raw_data',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'amount' => 'integer',
            'source' => TransactionSource::class,
            'raw_data' => 'array',
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

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Label, $this, LabelTransaction, 'pivot'> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)
            ->using(LabelTransaction::class)
            ->withTimestamps();
    }

    /** @return HasMany<BudgetTransaction, $this> */
    public function budgetTransactions(): HasMany
    {
        return $this->hasMany(BudgetTransaction::class);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        if (isset($filters['date_from'])) {
            $query->whereDate('transaction_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('transaction_date', '<=', $filters['date_to']);
        }

        if (isset($filters['amount_min'])) {
            $query->where('amount', '>=', $filters['amount_min'] * 100);
        }

        if (isset($filters['amount_max'])) {
            $query->where('amount', '<=', $filters['amount_max'] * 100);
        }

        $hasCategoryFilter = ! empty($filters['category_ids']);
        $hasLabelFilter = ! empty($filters['label_ids']);

        if ($hasCategoryFilter || $hasLabelFilter) {
            $realIds = [];
            $hasUncategorized = false;

            if ($hasCategoryFilter) {
                $ids = collect($filters['category_ids']);
                $hasUncategorized = $ids->contains('uncategorized');
                $realIds = $ids->reject(fn ($id) => $id === 'uncategorized')->values()->all();

                if ($realIds !== []) {
                    $userId = $filters['user_id'] ?? Category::query()->whereIn('id', $realIds)->value('user_id');

                    if ($userId !== null) {
                        $realIds = app(CategoryTree::class)->expand($userId, $realIds);
                    }
                }
            }

            $labelIds = $filters['label_ids'] ?? [];

            $query->where(function (Builder $outer) use ($hasCategoryFilter, $realIds, $hasUncategorized, $hasLabelFilter, $labelIds) {
                if ($hasCategoryFilter) {
                    $outer->where(function (Builder $q) use ($realIds, $hasUncategorized) {
                        if (! empty($realIds)) {
                            $q->whereIn('category_id', $realIds);
                        }
                        if ($hasUncategorized) {
                            $q->orWhereNull('category_id');
                        }
                    });
                }

                if ($hasLabelFilter) {
                    $outer->orWhereHas('labels', fn (Builder $q) => $q->whereIn('labels.id', $labelIds));
                }
            });
        }

        if (! empty($filters['account_ids'])) {
            $query->whereIn('account_id', $filters['account_ids']);
        }

        if (! empty($filters['creditor_name'])) {
            $term = '%'.$filters['creditor_name'].'%';
            $query->where('creditor_name', 'LIKE', $term);
        }

        if (! empty($filters['debtor_name'])) {
            $term = '%'.$filters['debtor_name'].'%';
            $query->where('debtor_name', 'LIKE', $term);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn (Builder $q) => $q
                ->where('description', 'LIKE', $term)
                ->orWhere('notes', 'LIKE', $term)
                ->orWhere('creditor_name', 'LIKE', $term)
                ->orWhere('debtor_name', 'LIKE', $term));
        }

        return $query;
    }
}
