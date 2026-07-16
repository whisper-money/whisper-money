<?php

namespace App\Models;

use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\RuleOrigin;
use App\Enums\TransactionSource;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Models\Concerns\BelongsToSpace;
use App\Services\CategoryTree;
use Carbon\Carbon;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property TransactionSource $source
 * @property ?CategorySource $category_source
 * @property ?float $ai_confidence
 * @property ?string $categorized_by_rule_id
 * @property ?string $ai_suggested_category_id
 * @property ?Carbon $ai_suggested_category_at
 * @property ?string $ai_model
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    /** @var array<string, class-string> */
    protected $dispatchesEvents = [
        'created' => TransactionCreated::class,
        'updated' => TransactionUpdated::class,
        'deleted' => TransactionDeleted::class,
    ];

    protected $fillable = [
        'user_id',
        'space_id',
        'account_id',
        'category_id',
        'category_source',
        'ai_confidence',
        'categorized_by_rule_id',
        'ai_suggested_category_id',
        'ai_suggested_category_at',
        'ai_model',
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
        'space_id',
        'original_description',
        'external_transaction_id',
        'dedup_fingerprint',
        'raw_data',
        'categorized_by_rule_id',
        'ai_model',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'amount' => 'integer',
            'source' => TransactionSource::class,
            'category_source' => CategorySource::class,
            'ai_confidence' => 'float',
            'ai_suggested_category_at' => 'datetime',
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

    /**
     * A transaction always lives in its account's space (the account is the
     * tenant anchor), so bank-sync inserts land in the right space regardless of
     * whichever space the syncing user is currently viewing.
     */
    protected function resolveDefaultSpaceId(): ?string
    {
        $accountId = $this->getAttribute('account_id');

        if ($accountId !== null) {
            $spaceId = Account::query()->whereKey($accountId)->value('space_id');

            if ($spaceId !== null) {
                return $spaceId;
            }
        }

        return $this->spaceIdFromUser();
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The type of the assigned category, resilient to phantom categories that
     * are force-filled with a raw string type (e.g. the synthetic
     * "uncategorized" rows the analytics controllers build).
     */
    public function categoryType(): ?CategoryType
    {
        $type = $this->category?->getAttribute('type');

        if ($type instanceof CategoryType) {
            return $type;
        }

        return is_string($type) ? CategoryType::tryFrom($type) : null;
    }

    /**
     * Whether this transaction sits on the income side of a cashflow split:
     * booked to an income category (a reversal there nets back out) or an
     * uncategorized inflow. Internal movements (transfer, savings, investment)
     * belong to neither side.
     *
     * Reads categoryType(), so callers should eager-load the category relation
     * when classifying a collection to avoid an N+1.
     */
    public function isIncomeSide(): bool
    {
        return $this->categoryType() === CategoryType::Income
            || ($this->category_id === null && $this->amount > 0);
    }

    /**
     * Whether this transaction sits on the expense side: booked to an expense
     * category (a refund there nets back out) or an uncategorized outflow.
     *
     * Reads categoryType(), so callers should eager-load the category relation
     * when classifying a collection to avoid an N+1.
     */
    public function isExpenseSide(): bool
    {
        return $this->categoryType() === CategoryType::Expense
            || ($this->category_id === null && $this->amount < 0);
    }

    /** @return BelongsTo<AutomationRule, $this> */
    public function categorizedByRule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'categorized_by_rule_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'ai_suggested_category_id');
    }

    /**
     * Whether AI assigned this transaction's category — either directly or via an
     * AI-owned rule. Not appended by default; surfaces opt in (e.g. the index
     * controller eager-loads `categorizedByRule:id,origin` and appends this) so
     * the rule-origin check never triggers a lazy load.
     *
     * @return Attribute<bool, never>
     */
    protected function aiCategorized(): Attribute
    {
        return Attribute::make(get: function (): bool {
            if ($this->category_source === CategorySource::Ai) {
                return true;
            }

            if (! $this->relationLoaded('categorizedByRule')) {
                return false;
            }

            return $this->categorizedByRule?->origin === RuleOrigin::Ai;
        });
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
     * Transactions the AI backfill can act on: still uncategorized and stored
     * in plaintext (encrypted descriptions are never sent to the AI provider).
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopePendingAiCategorization(Builder $query): Builder
    {
        return $query->whereNull('category_id')->whereNull('description_iv');
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

        if (! empty($filters['category_source'])) {
            $query->where('category_source', $filters['category_source']);
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
