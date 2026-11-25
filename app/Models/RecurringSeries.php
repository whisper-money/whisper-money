<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Enums\RecurringCadence;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Database\Factories\RecurringSeriesFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A charge that repeats on a recognisable cadence — a subscription, a utility
 * bill, a standing loan payment. A series is metadata over transactions that
 * already exist in the ledger; it never creates ledger rows of its own.
 *
 * @property RecurringCadence $cadence
 * @property ?array<int, string> $identity_aliases
 * @property RecurringSeriesStatus $status
 * @property RecurringSeriesUserState $user_state
 * @property Carbon $first_occurred_on
 * @property Carbon $last_occurred_on
 * @property Carbon $next_expected_on
 * @property ?Carbon $last_reminded_on
 */
class RecurringSeries extends Model
{
    /** @use HasFactory<RecurringSeriesFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'recurring_series';

    protected $fillable = [
        'user_id',
        'space_id',
        'match_field',
        'merchant_key',
        'identity_key',
        'identity_aliases',
        'merged_into_id',
        'display_name',
        'cadence',
        'interval_days',
        'anchor_day',
        'expected_amount',
        'recent_amount',
        'price_alerted_amount',
        'amount_is_variable',
        'currency_code',
        'account_id',
        'category_id',
        'direction',
        'first_occurred_on',
        'last_occurred_on',
        'next_expected_on',
        'occurrence_count',
        'status',
        'user_state',
        'last_reminded_on',
    ];

    /** @var list<string> */
    protected $hidden = ['space_id'];

    protected function casts(): array
    {
        return [
            'cadence' => RecurringCadence::class,
            'status' => RecurringSeriesStatus::class,
            'user_state' => RecurringSeriesUserState::class,
            'interval_days' => 'integer',
            'anchor_day' => 'integer',
            'expected_amount' => 'integer',
            'recent_amount' => 'integer',
            'price_alerted_amount' => 'integer',
            'occurrence_count' => 'integer',
            'amount_is_variable' => 'boolean',
            'identity_aliases' => 'array',
            'first_occurred_on' => 'date',
            'last_occurred_on' => 'date',
            'next_expected_on' => 'date',
            'last_reminded_on' => 'date',
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

    /**
     * The charges the series was detected from. Each is a movement
     * ({@see Transaction::scopeMovements()}): a charge the user split since
     * stays here as its original, trashed but with its full amount, while one
     * the user deleted drops out.
     *
     * @return BelongsToMany<Transaction, $this, RecurringSeriesTransaction, 'pivot'>
     */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'recurring_series_transaction')
            ->using(RecurringSeriesTransaction::class)
            ->withTimestamps()
            ->movements();
    }

    /**
     * Series the user has not dismissed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('user_state', '!=', RecurringSeriesUserState::Ignored);
    }

    /**
     * The series a forecast walks forward: still billing, still wanted, still
     * here. Soft deletes are handled by the model's own scope.
     *
     * The everyday-spending estimate leaves out exactly the transactions these
     * series claim, on the grounds that the forecast already counts them. That
     * only holds while both sides read the same set, so the definition lives
     * here rather than being spelled out again at each call site: the two
     * drifting apart is how spending falls through the gap between them.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStillCharging(Builder $query): Builder
    {
        return $query->visible()->where('status', RecurringSeriesStatus::Active);
    }

    /**
     * What this series charges, as against what it has charged.
     *
     * `expected_amount` is the median of the whole history, which answers a
     * different question and answers it badly once a price moves: a policy that
     * went from 24 to 104 euros still reads as 24 for as long as the cheap years
     * outnumber the dear ones. Everything that asks "how much will leave the
     * account" — the forecast, the summary, the reminder — wants this instead.
     * The history stays available for saying what changed.
     */
    public function chargeAmount(): int
    {
        return (int) ($this->recent_amount ?? $this->expected_amount);
    }

    /**
     * The charge rescaled to a month, so cadences can be summed and ranked
     * against each other.
     */
    public function monthlyEquivalentAmount(): int
    {
        return (int) round($this->chargeAmount() * $this->cadence->monthlyFactor());
    }

    /**
     * The identity keys seen on this series' charges, whatever the column
     * happens to hold. The cast gives an array, but a row written before the
     * column existed gives null, and every caller was repeating the same guard.
     *
     * @return list<string>
     */
    public function identityAliases(): array
    {
        $aliases = $this->identity_aliases;

        return is_array($aliases) ? array_values(array_filter(array_map('strval', $aliases))) : [];
    }

    /**
     * Whether this series only moves money between the user's own accounts.
     *
     * Read from the category rather than stored, so re-categorising a movement
     * corrects the totals at once instead of waiting for the nightly scan — and
     * re-categorising is exactly how a user fixes one that was filed wrong.
     *
     * Only `Transfer` counts here. A pension or savings contribution is also
     * internal in the sense that the money is not gone, but it does leave the
     * current account, so a runway has to keep counting it; net-worth projection
     * makes the opposite call for the same rows, and reads them from its own
     * list for that reason.
     */
    public function isInternal(): bool
    {
        return $this->category?->type === CategoryType::Transfer;
    }

    public function isIgnored(): bool
    {
        return $this->user_state === RecurringSeriesUserState::Ignored;
    }
}
