<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToSpace;
use Database\Factories\SavingsGoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property Carbon $created_at
 * @property Carbon|null $target_date
 * @property Carbon|null $archived_at
 * @property int|null $archived_saved_amount
 */
class SavingsGoal extends Model
{
    /** @use HasFactory<SavingsGoalFactory> */
    use Archivable, BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'space_id',
        'label_id',
        'name',
        'position',
        'target_amount',
        'initial_amount',
        'target_date',
        'archived_at',
        'archived_saved_amount',
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
        // Archiving soft-deletes the label, so it has to be read through the
        // trashed scope or an archived goal loses the name it saved under.
        $goals = $user->savingsGoals()->orderBy('position')->orderBy('name')->with(['label' => fn ($query) => $query->withTrashed()])->get();

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
        })->all();
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
