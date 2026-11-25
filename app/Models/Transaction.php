<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\CashflowBlock;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\RuleOrigin;
use App\Enums\TransactionSource;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Models\Concerns\BelongsToSpace;
use App\Services\CashflowBlockQuery;
use App\Services\CategoryTree;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Database\Factories\TransactionFactory;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @property Carbon $transaction_date
 * @property-read ?bool $splits_exists whether it has split lines, selected by `withExists('splits')` and absent otherwise
 * @property-read ?int $ownership_percentage the owning account's share, selected by {@see self::scopeJoinOwningAccount()} and absent otherwise
 * @property ?Carbon $source_date
 * @property ?string $split_parent_id
 * @property int|float $total_amount
 * @property TransactionSource $source
 * @property ?CategorySource $category_source
 * @property ?float $ai_confidence
 * @property ?string $categorized_by_rule_id
 * @property ?string $ai_suggested_category_id
 * @property ?Carbon $ai_suggested_category_at
 * @property ?string $ai_model
 * @property ?string $import_id
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    /** Preserve microsecond cursor precision for transaction delta sync. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

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
        'import_id',
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
        'import_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'source_date' => 'date:Y-m-d',
            'amount' => 'integer',
            'source' => TransactionSource::class,
            'category_source' => CategorySource::class,
            'ai_confidence' => 'float',
            'ai_suggested_category_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    /**
     * Keep the date the source gave a row once the user moves it onto another
     * day, so the sync watermark and the derived balance history stay on the
     * source's timeline instead of following the edit.
     *
     * Only the first move records it - the point is where the source put the row,
     * not where the user last had it. Manual rows have no source timeline to
     * preserve, so they keep the column null.
     */
    protected static function booted(): void
    {
        static::updating(function (Transaction $transaction): void {
            if ($transaction->source === TransactionSource::ManuallyCreated) {
                return;
            }

            if ($transaction->source_date !== null || ! $transaction->isDirty('transaction_date')) {
                return;
            }

            $transaction->source_date = $transaction->getOriginal('transaction_date');
        });
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

    /** @return HasMany<TransactionSplit, $this> */
    public function splits(): HasMany
    {
        return $this->hasMany(TransactionSplit::class)->orderBy('position');
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
     * The parts this transaction was split into (TransactionSplitter).
     * Empty unless this row is a split original. Upstream's `splits()`: it is
     * renamed to that once the legacy split lines are gone (PR 3).
     *
     * @return HasMany<Transaction, $this>
     */
    public function splitParts(): HasMany
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
     * What the table needs off each sibling. Scopes do not compose into a dotted
     * `with()` path, so nested eager loads spell the relation out and reuse this.
     */
    public const SPLIT_SIBLING_COLUMNS = 'id,split_parent_id,category_id,amount';

    /**
     * Load the other parts of a split onto whichever of these rows are parts.
     *
     * Deliberately not an eager load on the query: `with()` runs its statement
     * even when every `split_parent_id` is null, which is every page of every
     * user who has never split anything. This way the extra query happens only
     * when there is something to explain.
     *
     * @param  SupportCollection<int, Transaction>|EloquentCollection<int, Transaction>  $transactions
     */
    public static function loadSplitSiblings($transactions): void
    {
        $parts = EloquentCollection::make(
            $transactions->filter(fn (Transaction $transaction): bool => $transaction->isSplitPart())->all()
        );

        if ($parts->isEmpty()) {
            return;
        }

        $parts->load('splitSiblings:'.self::SPLIT_SIBLING_COLUMNS);
    }

    /**
     * One movement of money per row, as the account made it: a row that is not
     * a part of a split and is either live, or a split original (soft-deleted
     * by TransactionSplitter) that still has at least one live part. A
     * row the user deleted, and an original whose parts are all gone, are not.
     *
     * For the readers that reason about what the bank moved rather than how
     * the user filed it (recurring detection, statements, import dedup). It
     * lifts the soft-delete scope itself, so it works on a plain query.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeMovements(Builder $query): Builder
    {
        return $query->withTrashed()
            ->whereNull($query->qualifyColumn('split_parent_id'))
            ->where(fn (Builder $movement): Builder => $movement
                ->whereNull($query->qualifyColumn('deleted_at'))
                ->orWhereHas('splitParts'));
    }

    /**
     * One split line read as a posting of its own: a copy of this transaction
     * with the line's category and $amount, carrying everything else (date,
     * account, labels, notes) from the transaction. Cashflow and the CSV
     * export both expand a split transaction into these.
     */
    public function splitPosting(TransactionSplit $line, int $amount, ?string $currencyCode): self
    {
        $posting = clone $this;
        $posting->forceFill(['amount' => $amount, 'currency_code' => $currencyCode, 'category_id' => $line->category_id]);
        $posting->setRelation('category', $line->category);
        $posting->setRelation('splits', collect());

        return $posting;
    }

    /** @return Attribute<bool, never> */
    protected function isSplit(): Attribute
    {
        return Attribute::make(get: fn (): bool => $this->relationLoaded('splits') && $this->splits->isNotEmpty());
    }

    /** @return Attribute<int, never> */
    protected function splitCount(): Attribute
    {
        return Attribute::make(get: fn (): int => $this->relationLoaded('splits') ? $this->splits->count() : 0);
    }

    /**
     * The type of the assigned category, resilient to phantom categories that
     * are force-filled with a raw string type (e.g. the synthetic
     * "uncategorized" rows the analytics controllers build).
     */
    public function categoryType(): ?CategoryType
    {
        $type = $this->category?->getAttribute('type');

        if ($type === null && $this->relationLoaded('splits') && $this->splits->isNotEmpty()) {
            $type = $this->splits->first()->category?->getAttribute('type');
        }

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
            || ($this->category_id === null && (! $this->relationLoaded('splits') || $this->splits->isEmpty()) && $this->amount > 0);
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
            || ($this->category_id === null && (! $this->relationLoaded('splits') || $this->splits->isEmpty()) && $this->amount < 0);
    }

    /**
     * Whether this is a savings or investment row booked on a savings,
     * investment or retirement account, in either direction. That money is
     * already counted by the leg on the other account, so cashflow skips this
     * one — interest booked here included; that belongs in an income category.
     *
     * Reads the category and account relations, so eager-load both when
     * classifying a collection.
     */
    public function isSetAsideAccountLeg(): bool
    {
        return $this->categoryType()?->isSetAside() === true
            && $this->account?->type?->isSetAside() === true;
    }

    /**
     * Rows with at least one posting {@see self::isExpenseSide()} accepts: the
     * SQL twin of that check, read per posting the way
     * `effectiveTransactions()` expands a row. An unsplit row is its own
     * posting (an expense category, or an uncategorized outflow); a split row
     * has one per line, each in its own category, so it matches when any line
     * is in an expense category. Both must keep agreeing: the cashflow summary
     * adds up with one and the transactions list filters with the other.
     *
     * $postingCategory narrows the posting further by its category: it gets
     * the query the posting is read from (the row's, or a split line's) and
     * the column holding the posting's category.
     *
     * @param  Builder<Transaction>  $query
     * @param  (Closure(Builder<covariant Model>, string): mixed)|null  $postingCategory
     * @return Builder<Transaction>
     */
    public function scopeExpenseSide(Builder $query, ?Closure $postingCategory = null): Builder
    {
        return $query->whereHasPosting(function (Builder $posting, string $categoryColumn) use ($postingCategory): void {
            $posting->where(fn (Builder $side) => $side
                ->whereHas('category', fn (Builder $category) => $category->where('type', CategoryType::Expense))
                // Only ever true for an unsplit row: a split line always has a category.
                ->orWhere(fn (Builder $uncategorized) => $uncategorized
                    ->whereNull($categoryColumn)
                    ->where('transactions.amount', '<', 0)));

            if ($postingCategory !== null) {
                $postingCategory($posting, $categoryColumn);
            }
        });
    }

    /**
     * Rows with a posting booked to a $type category, minus the legs
     * {@see self::isSetAsideAccountLeg()} skips: the SQL twin of what the
     * cashflow summary adds up as savings or investments, read per posting
     * (an unsplit row, or any line of a split one). The account is the row's,
     * so a leg skips every line of it, as the summary does.
     *
     * Only valid on queries that ran {@see self::scopeJoinOwningAccount()}. The
     * join ignores the account's soft-delete scope while the `account` relation
     * does not, so a trashed account's row is not skipped here either.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeSetAsideInto(Builder $query, CategoryType $type): Builder
    {
        $setAsideAccountTypes = array_values(array_filter(
            AccountType::cases(),
            fn (AccountType $accountType): bool => $accountType->isSetAside(),
        ));

        return $query
            ->whereHasPosting(fn (Builder $posting) => $posting
                ->whereHas('category', fn (Builder $category) => $category->where('type', $type)))
            ->whereNot(fn (Builder $leg) => $leg
                ->whereNull('accounts.deleted_at')
                ->whereIn('accounts.type', $setAsideAccountTypes));
    }

    /**
     * Rows with at least one posting $posting accepts, a posting being what
     * `effectiveTransactions()` turns a row into: an unsplit row is its own,
     * a split row has one per line. $posting runs against the row (when it
     * has no splits) or against its split lines, and gets the column holding
     * the posting's category. Labels stay on the row, so a label condition
     * goes outside, on the row itself.
     *
     * @param  Builder<Transaction>  $query
     * @param  Closure(Builder<covariant Model>, string): mixed  $posting
     * @return Builder<Transaction>
     */
    public function scopeWhereHasPosting(Builder $query, Closure $posting): Builder
    {
        return $query->where(fn (Builder $any) => $any
            ->where(function (Builder $unsplit) use ($posting): void {
                $posting($unsplit->whereDoesntHave('splits'), 'transactions.category_id');
            })
            ->orWhereHas('splits', function (Builder $line) use ($posting): void {
                $posting($line, 'transaction_splits.category_id');
            }));
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
     * Transactions the AI backfill and the AI rule suggestions (their groups
     * and the rows a suggested rule files) can act on: still uncategorized,
     * stored in plaintext (encrypted descriptions are never sent to the AI
     * provider) and not left blank on purpose by an import. A full import
     * brings a row split in Money Pro in uncategorized and marked as
     * categorized by hand, for the user to split here, so the AI must not
     * file it whole. Only rows an
     * import wrote are narrowed this way: elsewhere a blank row marked manual
     * can be one whose category the user deleted, which the AI may file again
     * ({@see Transaction::scopeOpenToImportAi()}). Written as a positive OR so
     * rows with a null import or category source stay in.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopePendingAiCategorization(Builder $query): Builder
    {
        return $query->whereNull('category_id')
            ->whereNull('description_iv')
            ->whereDoesntHave('splits')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('import_id')
                ->orWhereNull('category_source')
                ->orWhere('category_source', '!=', CategorySource::Manual->value));
    }

    /**
     * The uncategorized rows of a full import its single AI pass may file:
     * all but the ones it left blank on purpose and marked as categorized by
     * hand, the rows split in Money Pro the user is to split here. Only the
     * import narrows by this: elsewhere a blank row marked manual can be one
     * whose category the user deleted, which the AI may file again.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeOpenToImportAi(Builder $query): Builder
    {
        return $query->whereNull('category_id')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('category_source')
                ->orWhere('category_source', '!=', CategorySource::Manual->value));
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
     * A posting's amount and category in SQL, the twin of what
     * `effectiveTransactions()` expands a row into: an unsplit row is its own
     * posting, a split row one per line. Only valid on queries that ran
     * {@see self::scopeJoinPostingLines()}; a split line always has an amount
     * and a category, so the row's own only stand in when it has no lines.
     */
    public const POSTING_AMOUNT_SQL = 'coalesce(transaction_splits.amount, transactions.amount)';

    public const POSTING_CATEGORY_SQL = 'coalesce(transaction_splits.category_id, transactions.category_id)';

    /**
     * {@see self::OWNED_AMOUNT_SQL} for a posting, rounded per posting as
     * {@see Account::shareOf()} weighs a split line.
     */
    public const OWNED_POSTING_AMOUNT_SQL = 'round('.self::POSTING_AMOUNT_SQL.' * accounts.ownership_percentage / 100)';

    /**
     * One row per posting: an unsplit transaction once, a split one once per
     * line. Pair it with {@see self::POSTING_AMOUNT_SQL} and
     * {@see self::POSTING_CATEGORY_SQL}; labels stay on the transaction, so a
     * label keeps every line.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeJoinPostingLines(Builder $query): Builder
    {
        return $query->leftJoin('transaction_splits', 'transaction_splits.transaction_id', '=', 'transactions.id');
    }

    /**
     * Join the owning account so aggregates can weigh each amount by the
     * account's ownership percentage. Pair it with {@see self::ownedAmount()}.
     *
     * `account_id` is NOT NULL and the join deliberately ignores the account's
     * soft-delete scope, so the row set is exactly what it was before the
     * ownership weighting existed.
     *
     * A query that adds up a period — spending, cash flow, a category total —
     * also wants {@see self::scopeWithoutArchivedAccountActivity()}, or an
     * archived account keeps feeding the figure. A running accumulation is the
     * exception: SavingsGoal's tagged contributions deliberately count archived
     * accounts, since money set aside stays set aside.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeJoinOwningAccount(Builder $query): Builder
    {
        return $query->join('accounts', 'accounts.id', '=', 'transactions.account_id');
    }

    /**
     * Drop what an archived account should no longer contribute: a transaction
     * dated on or after the day the account was archived. Earlier ones keep
     * counting, so archiving never moves the history retroactively. This mirrors
     * AccountMetricsService, which freezes an archived account's balance from the
     * same day, and BudgetTransactionService, where an archived budget stops
     * taking in new spending from its own archive date.
     *
     * Spelled out rather than via Account::scopeNotArchived, which would drop
     * the account's whole past too.
     *
     * The cutoff day is `archived_at` read in UTC, not in the user's timezone,
     * matching how the balance side reads it. At a non-zero offset that can land
     * a day either side of the user's own day; fixing it means fixing both paths
     * together, or they would disagree about when an account stopped counting.
     *
     * Only valid on queries that ran {@see self::scopeJoinOwningAccount()}.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeWithoutArchivedAccountActivity(Builder $query): Builder
    {
        return $query->where(function (Builder $group): void {
            // date() on archived_at: it carries a time of day, transaction_date
            // does not, so a raw comparison would let the archiving day through.
            $group->whereNull('accounts.archived_at')
                ->orWhereRaw('transactions.transaction_date < date(accounts.archived_at)');
        });
    }

    /**
     * Only the rows a period total should count: the same cutoff as
     * {@see self::scopeWithoutArchivedAccountActivity()}, packaged for the
     * queries that hydrate models and add up in PHP rather than in SQL.
     *
     * It re-selects `transactions.*` because the join drags the account's own
     * columns into the default `select *`, where `id`, `user_id` and
     * `currency_code` would overwrite the transaction's own values.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeCountingTowardsTotals(Builder $query): Builder
    {
        return $query
            ->joinOwningAccount()
            ->withoutArchivedAccountActivity()
            ->select('transactions.*');
    }

    /**
     * {@see self::OWNED_AMOUNT_SQL} as an expression, for `sum()` and friends.
     */
    public static function ownedAmount(): Expression
    {
        return DB::raw(self::OWNED_AMOUNT_SQL);
    }

    /**
     * Inclusive `transaction_date` range, either end optional.
     *
     * Plain comparisons rather than `whereDate()`: the column is already a
     * DATE, and MySQL wraps a `whereDate()` column in `cast(... as date)`,
     * which keeps the range off `idx_transactions_budget_lookup` and reads
     * every row the user has. Bounds are cut to `Y-m-d` first so a value with
     * a time of day (`2026-01-31 15:00`) still includes its own day.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeTransactionDateWithin(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when(filled($from), fn (Builder $q) => $q->where('transaction_date', '>=', self::dateBound($from)))
            ->when(filled($to), fn (Builder $q) => $q->where('transaction_date', '<=', self::dateBound($to)));
    }

    /**
     * An unparseable bound goes through untouched rather than throwing or being
     * dropped: dropping it would widen what a bulk update or rule re-evaluation
     * writes to, and MySQL matches nothing against it, as `whereDate()` did.
     */
    private static function dateBound(string $value): string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (InvalidFormatException) {
            return $value;
        }
    }

    /**
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function scopeApplyFilters(Builder $query, array $filters): Builder
    {
        $query
            ->transactionDateWithin($filters['date_from'] ?? null, $filters['date_to'] ?? null)
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
        $this->applyCategoryTypeFilter($query, $filters);
        $this->applyCashflowBlockFilter($query, $filters);

        return $query;
    }

    /**
     * The transactions list's order: newest first, then `id` so rows of one
     * day keep one stable order across pages and exports.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeSortedForList(Builder $query): Builder
    {
        return $query->orderByDesc('transaction_date')->orderByDesc('id');
    }

    /**
     * A cashflow block is only defined over a period, so the filter needs both
     * dates (the requests require them alongside it) and the owner whose
     * budgets decide the expense blocks.
     *
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyCashflowBlockFilter(Builder $query, array $filters): void
    {
        if (empty($filters['cashflow_block'])) {
            return;
        }

        if (! isset($filters['user_id'], $filters['date_from'], $filters['date_to'])) {
            throw new LogicException('The cashflow block filter needs user_id, date_from and date_to.');
        }

        app(CashflowBlockQuery::class)->apply(
            $query,
            CashflowBlock::from($filters['cashflow_block']),
            $filters['user_id'],
            PeriodComparator::fromRequest(['from' => $filters['date_from'], 'to' => $filters['date_to']]),
        );
    }

    /**
     * A transaction whose category has one of the types, or a split transaction
     * with a line that does: as with a category filter, a split transaction is
     * filed under its postings. It ANDs with the category and label group, so a
     * row with no category, which has no type, never matches.
     *
     * @param  Builder<Transaction>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyCategoryTypeFilter(Builder $query, array $filters): void
    {
        if (empty($filters['category_types'])) {
            return;
        }

        $ofType = fn (Builder $category) => $category->whereIn('type', $filters['category_types']);

        $query->where(fn (Builder $group) => $group
            ->whereHas('category', $ofType)
            ->orWhereHas('splits.category', $ofType));
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
                // A split transaction is filed under its postings, not its own
                // column: its category_id is usually null while the splits carry
                // the categories the user is filtering on.
                $group->where(fn (Builder $q) => $q
                    ->whereIn('category_id', $wantedCategoryIds)
                    ->orWhereHas('splits', fn (Builder $splits) => $splits->whereIn('category_id', $wantedCategoryIds)));
            }

            if ($wantsUncategorized) {
                // Split transactions are categorized by their postings, so a null
                // category_id alone does not make one uncategorized.
                $group->orWhere(fn (Builder $q) => $q->whereNull('category_id')->whereDoesntHave('splits'));
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
