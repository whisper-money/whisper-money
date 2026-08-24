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
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * @property Carbon $transaction_date
 * @property ?string $split_parent_id
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
        'split_parent_id',
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
     * The original this row was split off from. It is soft-deleted, so every
     * read of it needs `withTrashed()`.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function splitParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'split_parent_id');
    }

    /**
     * The parts this transaction was split into. Empty unless this row is a
     * split original.
     *
     * @return HasMany<Transaction, $this>
     */
    public function splits(): HasMany
    {
        return $this->hasMany(self::class, 'split_parent_id');
    }

    /**
     * Whether this row is one part of a split rather than a transaction the
     * account produced on its own.
     */
    public function isSplitPart(): bool
    {
        return $this->split_parent_id !== null;
    }

    /**
     * Every part of the same split, this one included. Keyed on the shared
     * parent id, so explaining a part to the user costs no read of the
     * soft-deleted original: the parts add up to it by construction.
     *
     * @return HasMany<Transaction, $this>
     */
    public function splitSiblings(): HasMany
    {
        return $this->hasMany(self::class, 'split_parent_id', 'split_parent_id');
    }

    /**
     * Eager-load what a split part needs to explain itself in the table: the
     * other parts of the same split. One extra query for the whole page, and
     * nothing at all for rows that are not part of a split.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeWithSplitSiblings(Builder $query): Builder
    {
        return $query->with('splitSiblings:id,split_parent_id,category_id,amount');
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
     * The owner's share of an amount held by this transaction's account, for
     * the row-by-row PHP paths. Falls back to the full amount when the account
     * is not loaded, so a partial select never silently zeroes the figure.
     */
    public function ownerShareOf(int $amount): int
    {
        return $this->account?->shareOfAmount($amount) ?? $amount;
    }

    /**
     * A transaction amount reduced to the owner's share of its account, for
     * SQL-side aggregates. Rounds per row, matching {@see Account::shareOfAmount()}
     * (MySQL and PHP both round half away from zero).
     * Only valid on queries that ran {@see self::scopeJoinOwningAccount()}.
     */
    public const OWNED_AMOUNT_SQL = 'round(transactions.amount * accounts.ownership_percentage / 100)';

    /**
     * Join the owning account so aggregates can weigh each amount by the
     * account's ownership percentage. Pair it with {@see self::ownedAmount()}.
     *
     * `account_id` is NOT NULL and the join deliberately ignores the account's
     * soft-delete scope, so the row set is exactly what it was before the
     * ownership weighting existed.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeJoinOwningAccount(Builder $query): Builder
    {
        return $query->join('accounts', 'accounts.id', '=', 'transactions.account_id');
    }

    /**
     * {@see self::OWNED_AMOUNT_SQL} as an expression, for `sum()` and friends.
     */
    public static function ownedAmount(): Expression
    {
        return DB::raw(self::OWNED_AMOUNT_SQL);
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        $query
            ->when(isset($filters['date_from']), fn (Builder $q) => $q->whereDate('transaction_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $q) => $q->whereDate('transaction_date', '<=', $filters['date_to']))
            // Amounts arrive in major units from the UI but are stored in cents.
            ->when(isset($filters['amount_min']), fn (Builder $q) => $q->where('amount', '>=', $filters['amount_min'] * 100))
            ->when(isset($filters['amount_max']), fn (Builder $q) => $q->where('amount', '<=', $filters['amount_max'] * 100))
            ->when(! empty($filters['account_ids']), fn (Builder $q) => $q->whereIn('account_id', $filters['account_ids']))
            ->when(! empty($filters['category_source']), fn (Builder $q) => $q->where('category_source', $filters['category_source']))
            ->when(! empty($filters['creditor_name']), fn (Builder $q) => $q->where('creditor_name', 'LIKE', '%'.$filters['creditor_name'].'%'))
            ->when(! empty($filters['debtor_name']), fn (Builder $q) => $q->where('debtor_name', 'LIKE', '%'.$filters['debtor_name'].'%'))
            ->when(! empty($filters['search']), fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('description', 'LIKE', '%'.$filters['search'].'%')
                    ->orWhere('notes', 'LIKE', '%'.$filters['search'].'%')
                    ->orWhere('creditor_name', 'LIKE', '%'.$filters['search'].'%')
                    ->orWhere('debtor_name', 'LIKE', '%'.$filters['search'].'%')
            ));

        $this->applyCategoryAndLabelFilters($query, $filters);

        return $query;
    }

    /**
     * Categories and labels are one filter, not two: a transaction matches when it
     * sits in a wanted category OR carries a wanted label, so both sides are ORed
     * together inside a single group.
     *
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyCategoryAndLabelFilters(Builder $query, array $filters): void
    {
        $categoryIds = empty($filters['category_ids']) ? [] : collect($filters['category_ids']);
        $labelIds = empty($filters['label_ids']) ? [] : $filters['label_ids'];

        if ($categoryIds === [] && $labelIds === []) {
            return;
        }

        // "uncategorized" is a pseudo id the UI sends for transactions with no
        // category at all, so it can be picked alongside real categories.
        $wantsUncategorized = $categoryIds !== [] && $categoryIds->contains('uncategorized');
        $wantedCategoryIds = $categoryIds === []
            ? []
            : $this->expandToDescendants(
                $categoryIds->reject(fn ($id) => $id === 'uncategorized')->values()->all(),
                $filters['user_id'] ?? null,
            );

        $query->where(function (Builder $group) use ($wantedCategoryIds, $wantsUncategorized, $labelIds): void {
            if ($wantedCategoryIds !== []) {
                $group->whereIn('category_id', $wantedCategoryIds);
            }

            if ($wantsUncategorized) {
                $group->orWhereNull('category_id');
            }

            if ($labelIds !== []) {
                $group->orWhereHas('labels', fn (Builder $q) => $q->whereIn('labels.id', $labelIds));
            }
        });
    }

    /**
     * Picking a category means picking everything under it, so the selection is
     * widened to the whole subtree. Left as-is when the owner cannot be resolved.
     *
     * @param  list<string>  $categoryIds
     * @return list<string>
     */
    private function expandToDescendants(array $categoryIds, ?string $userId): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $userId ??= Category::query()->whereIn('id', $categoryIds)->value('user_id');

        return $userId === null
            ? $categoryIds
            : app(CategoryTree::class)->expand($userId, $categoryIds);
    }
}
