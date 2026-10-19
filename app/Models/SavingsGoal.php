<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\MonthlyTargetType;
use App\Enums\SavingsGoalKind;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToSpace;
use Carbon\CarbonInterface;
use Database\Factories\SavingsGoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * @property Carbon $created_at
 * @property Carbon|null $target_date
 * @property Carbon|null $archived_at
 * @property int|null $archived_saved_amount
 * @property SavingsGoalKind $kind
 * @property MonthlyTargetType|null $monthly_target_type
 * @property int|null $monthly_target_amount
 * @property float|null $monthly_target_rate
 * @property bool $notify_on_month_end_reminder
 */
class SavingsGoal extends Model
{
    /** @use HasFactory<SavingsGoalFactory> */
    use Archivable, BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    /**
     * A monthly goal created in the last this-many days of a month gets that
     * month as a partial one, with no verdict.
     */
    public const LATE_START_DAYS = 5;

    /**
     * The largest amount, in minor units, a goal's target or starting amount
     * may be: 1,000,000,000.00. Well inside the integers a browser holds
     * exactly, so figures never drift by a cent on the way to the screen.
     */
    public const MAX_AMOUNT = 100_000_000_000;

    protected $fillable = [
        'user_id',
        'space_id',
        'label_id',
        'name',
        'kind',
        'position',
        'target_amount',
        'initial_amount',
        'target_date',
        'archived_at',
        'archived_saved_amount',
        'monthly_target_type',
        'monthly_target_amount',
        'monthly_target_rate',
        'notify_on_month_end_reminder',
        'auto_tag_account_id',
    ];

    /**
     * Mirrors the column defaults, so a goal that was just created reads the
     * same before and after a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => 'one_off',
        'notify_on_month_end_reminder' => true,
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'target_amount' => 'integer',
            'initial_amount' => 'integer',
            'target_date' => 'date:Y-m-d',
            'archived_at' => 'datetime',
            'archived_saved_amount' => 'integer',
            'kind' => SavingsGoalKind::class,
            'monthly_target_type' => MonthlyTargetType::class,
            'monthly_target_amount' => 'integer',
            'monthly_target_rate' => 'float',
            'notify_on_month_end_reminder' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Label, $this> */
    public function label(): BelongsTo
    {
        return $this->belongsTo(Label::class);
    }

    /**
     * The calendar months of a monthly goal, each with the target it was held to.
     *
     * @return HasMany<SavingsGoalPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(SavingsGoalPeriod::class);
    }

    public function isMonthly(): bool
    {
        return $this->kind === SavingsGoalKind::Monthly;
    }

    /**
     * Whether the goal was created in the last LATE_START_DAYS days of $month,
     * too late for that month to be held to a whole month's target.
     */
    public function startedLateIn(CarbonInterface $month): bool
    {
        $lateStart = $month->copy()->endOfMonth()->startOfDay()->subDays(self::LATE_START_DAYS - 1);

        return $this->created_at->gte($lateStart) && $this->created_at->lte($month->copy()->endOfMonth());
    }

    /**
     * Whether the goal was archived during $month.
     */
    public function wasArchivedIn(CarbonInterface $month): bool
    {
        return $this->archived_at !== null && $this->archived_at->isSameMonth($month);
    }

    /**
     * Whether $month gets no verdict: the goal started late in it, or was
     * archived during it.
     */
    public function isPartialMonth(CarbonInterface $month): bool
    {
        return $this->startedLateIn($month) || $this->wasArchivedIn($month);
    }

    /**
     * The running monthly goal whose auto-tag rule already watches $accountId.
     * Rules stop at the first match, so a second goal on the same account
     * would never see a transfer.
     */
    public static function autoTaggingAccount(string $accountId): ?self
    {
        return self::query()->monthly()->notArchived()->where('auto_tag_account_id', $accountId)->first();
    }

    /** @return BelongsTo<Account, $this> */
    public function autoTagAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'auto_tag_account_id');
    }

    /**
     * @param  Builder<SavingsGoal>  $query
     * @return Builder<SavingsGoal>
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('kind', SavingsGoalKind::Monthly->value);
    }

    /**
     * @param  Builder<SavingsGoal>  $query
     * @return Builder<SavingsGoal>
     */
    public function scopeOneOff(Builder $query): Builder
    {
        return $query->where('kind', SavingsGoalKind::OneOff->value);
    }

    /**
     * Goals in the order the budgets index lists them, with the label each one
     * saves under. Archiving soft-deletes the label, so it has to be read
     * through the trashed scope or an archived goal loses the name it saved under.
     *
     * @param  Builder<SavingsGoal>  $query
     * @return Builder<SavingsGoal>
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query->with(['label' => fn ($query) => $query->withTrashed()])
            ->orderBy('position')
            ->orderBy('name');
    }

    /**
     * What a tagged transaction contributes to a goal, as SQL. On a savings
     * account the money arriving IS the contribution, so the amount counts as
     * it stands (+ adds, a withdrawal subtracts). On any other account type the
     * tagged transaction is the outflow that funded the goal, so its sign is
     * negated: a transfer out (−) adds, a transfer back (+) subtracts.
     *
     * Only valid on queries that ran {@see Transaction::scopeJoinOwningAccount()}.
     */
    public const CONTRIBUTION_AMOUNT_SQL = "case when accounts.type = '".AccountType::Savings->value."' then transactions.amount else -transactions.amount end";

    /**
     * Transactions tagged with any of the given labels, joined to their owning
     * account so {@see self::CONTRIBUTION_AMOUNT_SQL} can read its type.
     *
     * @param  iterable<int, string>  $labelIds
     * @return Builder<Transaction>
     */
    private static function taggedContributions(iterable $labelIds): Builder
    {
        return Transaction::query()
            ->join('label_transaction', 'label_transaction.transaction_id', '=', 'transactions.id')
            ->joinOwningAccount()
            ->whereIn('label_transaction.label_id', $labelIds);
    }

    /**
     * What the transactions tagged with each label contributed per day since
     * $since, one row per label and day — `label_id`, `day` and `total` in
     * cents. Monthly goals fold these into months.
     *
     * @param  iterable<int, string>  $labelIds
     * @return SupportCollection<int, stdClass>
     */
    public static function contributionsByDay(iterable $labelIds, Carbon $since): SupportCollection
    {
        return self::taggedContributions($labelIds)
            ->where('transactions.transaction_date', '>=', $since->toDateString())
            ->groupBy('label_transaction.label_id', 'transactions.transaction_date')
            ->selectRaw('label_transaction.label_id as label_id, transactions.transaction_date as day, SUM('.self::CONTRIBUTION_AMOUNT_SQL.') as total')
            ->toBase()
            ->get();
    }

    /**
     * Money set aside toward the goal, in cents: whatever was already saved when
     * the goal was created plus what its tagged transactions contribute.
     *
     * An archived goal reads its snapshot instead. Recomputing would drift:
     * archiving deletes the label, so the sum would collapse to the starting
     * balance, and re-tagging one of those transactions afterwards would move a
     * figure that is meant to be final.
     *
     * @see self::CONTRIBUTION_AMOUNT_SQL for the sign rule.
     */
    public function savedAmountInCents(): int
    {
        if ($this->isArchived()) {
            return (int) $this->archived_saved_amount;
        }

        if ($this->label === null) {
            return $this->initial_amount;
        }

        return $this->initial_amount + (int) self::taggedContributions([$this->label_id])
            ->sum(DB::raw(self::CONTRIBUTION_AMOUNT_SQL));
    }

    /**
     * All of a user's goals with their computed progress, for the combined
     * budgets/goals index. Kept here (not in the budget controller) so budgets
     * stay decoupled from goals.
     *
     * @return list<array<string, mixed>>
     */
    public static function withStatsForUser(User $user): array
    {
        // Monthly goals have no total to reach; they are listed on their own.
        return self::withStats($user->savingsGoals()->oneOff()->listed()->get());
    }

    /**
     * The progress of one-off goals already loaded with their labels, so a page
     * that lists both kinds reads the goals once.
     *
     * @param  Collection<int, SavingsGoal>  $goals
     * @return list<array<string, mixed>>
     */
    public static function withStats(Collection $goals): array
    {
        // ponytail: one grouped sum+min for all goals' labels avoids N+1 across the list.
        $aggByLabel = self::taggedContributions($goals->pluck('label_id')->filter())
            ->groupBy('label_transaction.label_id')
            ->selectRaw('label_transaction.label_id as label_id, SUM('.self::CONTRIBUTION_AMOUNT_SQL.') as total, MIN(transactions.transaction_date) as earliest')
            ->get()
            ->keyBy('label_id');

        return $goals->map(function (SavingsGoal $goal) use ($aggByLabel): array {
            $agg = $aggByLabel->get($goal->label_id);
            // Starting balance plus the tagged contributions, mirroring
            // savedAmountInCents(); batched to avoid N+1. An archived goal keeps
            // the snapshot it froze.
            $saved = $goal->isArchived()
                ? (int) $goal->archived_saved_amount
                : $goal->initial_amount + (int) ($agg->total ?? 0);

            return array_merge($goal->toArray(), [
                'stats' => self::project(
                    $saved,
                    $goal->target_amount,
                    self::effectiveStart($goal->created_at, $agg->earliest ?? null),
                    $goal->target_date,
                    $goal->measuredAt(),
                    $goal->initial_amount,
                ),
            ]);
        })->values()->all();
    }

    /**
     * The moment the goal's figures are read at. An archived goal is frozen on
     * the day it was archived, so its pace and projection stop moving too — a
     * finished goal that keeps sliding further behind schedule reads as a bug.
     */
    public function measuredAt(): Carbon
    {
        return $this->archived_at ?? now();
    }

    /**
     * The goal's timeline start: the earlier of its creation date and its first
     * tagged transaction. Tagging pre-existing savings must not compress the
     * elapsed window, which would otherwise inflate the rate and projection.
     */
    public static function effectiveStart(Carbon $createdAt, Carbon|string|null $earliestContribution): Carbon
    {
        $start = $createdAt->copy()->startOfDay();

        if ($earliestContribution === null) {
            return $start;
        }

        $earliest = Carbon::parse($earliestContribution)->startOfDay();

        return $earliest->lt($start) ? $earliest : $start;
    }

    /**
     * Where the goal stands against its ideal pace, within a 2% tolerance band
     * so a few cents either way doesn't read as ahead or behind.
     */
    private static function status(int $saved, int $target, int $expectedToday): string
    {
        $tolerance = $target * 0.02;

        return match (true) {
            $saved >= $target => 'completed',
            $saved < $expectedToday - $tolerance => 'behind',
            $saved > $expectedToday + $tolerance => 'ahead',
            default => 'on_track',
        };
    }

    /**
     * Linear progress + projection, computed from primitives so it stays a pure,
     * testable function. Dates are day-granular. `rate_per_day` is cents/day and
     * feeds the chart's dotted projection line client-side.
     *
     * @return array{
     *     saved: int,
     *     target: int,
     *     percentage: float,
     *     target_date: ?string,
     *     rate_per_day: float,
     *     expected_today: ?int,
     *     status: ?string,
     *     estimated_date: ?string,
     *     required_per_month: ?int,
     * }
     */
    public static function project(int $saved, int $target, Carbon $start, ?Carbon $targetDate, Carbon $today, int $initialAmount = 0): array
    {
        $start = $start->copy()->startOfDay();
        $today = $today->copy()->startOfDay();

        $daysElapsed = max(1, $start->diffInDays($today));
        // Only what was added since the start sets the pace: the starting balance
        // was already there on day one, and counting it would read as a huge daily
        // rate and project completion almost immediately.
        $ratePerDay = ($saved - $initialAmount) / $daysElapsed;
        $remaining = $target - $saved;

        $percentage = $target > 0 ? round(($saved / $target) * 100, 1) : 0.0;

        $estimatedDate = null;
        if ($saved >= $target) {
            $estimatedDate = $today->toDateString();
        } elseif ($ratePerDay > 0) {
            $estimatedDate = $today->copy()->addDays((int) ceil($remaining / $ratePerDay))->toDateString();
        }

        $expectedToday = null;
        $status = null;
        $requiredPerMonth = null;

        if ($targetDate !== null) {
            $targetDate = $targetDate->copy()->startOfDay();
            $totalDays = max(1, $start->diffInDays($targetDate));
            // The ideal pace runs from the starting balance to the target, not from zero.
            $expectedToday = (int) round($initialAmount + ($target - $initialAmount) * min($daysElapsed, $totalDays) / $totalDays);

            $status = self::status($saved, $target, $expectedToday);

            $daysLeft = $today->diffInDays($targetDate, false);
            if ($remaining > 0 && $daysLeft > 0) {
                $requiredPerMonth = (int) round(($remaining / $daysLeft) * 30);
            } elseif ($remaining <= 0) {
                $requiredPerMonth = 0;
            }
        }

        return [
            'saved' => $saved,
            'target' => $target,
            'percentage' => $percentage,
            'target_date' => $targetDate?->toDateString(),
            'rate_per_day' => round($ratePerDay, 2),
            'expected_today' => $expectedToday,
            'status' => $status,
            'estimated_date' => $estimatedDate,
            'required_per_month' => $requiredPerMonth,
        ];
    }
}
