<?php

namespace App\Services\Forecast;

use App\Enums\ForecastEstimateKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\ForecastPlan;
use App\Services\Forecast\Data\Occurrence;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * The rows of the forecast plan of one account, as the page shows them: the
 * income and the spend grouped under the user's parent categories, each with
 * its concrete lines and its estimate, and the transfers apart. Amounts
 * are signed from the account's side (a transfer into it is positive) and
 * keyed by `Y-m` month, only for the months given, so nothing past them is
 * ever sent. The month in progress also says what really happened: a line is
 * `matched` once a transaction paid it, `pending` while it is still to come.
 */
class ForecastPlanRows
{
    /**
     * The group key of the spend no category covers.
     */
    private const OTHER = 'other';

    /**
     * Payments of a line listed as "planned vs real" in its edit sheet.
     */
    private const RECENT_PAYMENTS = 3;

    public function __construct(
        private readonly OccurrenceExpander $expander,
        private readonly ForecastHistory $history,
        private readonly ForecastDrift $drift,
        private readonly TransferEstimateRows $transferRows,
    ) {}

    /**
     * @param  list<string>  $months  The `Y-m` months shown, the month in progress first.
     * @return array{income: list<array<string, mixed>>, expenses: list<array<string, mixed>>, transfers: list<array<string, mixed>>}
     */
    public function build(User $user, ForecastPlan $plan, Account $account, array $months): array
    {
        $items = $plan->items
            ->filter(fn (ForecastItem $item): bool => $item->account_id === $account->id || $item->destination_account_id === $account->id)
            ->values();
        $estimates = array_values(array_filter($plan->categoryEstimates, fn (CategoryEstimate $estimate): bool => $estimate->accountId === $account->id));

        return $this->assemble($user, $items, $estimates, fn (): string => $account->id, $months, false, $this->transferRows->rows($plan->transferEstimates, $account->id, $months));
    }

    /**
     * The plan of every included account at once: each line is seen from its
     * own account, a transfer from its destination (so it reads as the
     * positive amount moved), and each parent category lists the estimated
     * rest of every account that has one, as `estimates`.
     *
     * @param  list<string>  $months  The `Y-m` months shown, the month in progress first.
     * @return array{income: list<array<string, mixed>>, expenses: list<array<string, mixed>>, transfers: list<array<string, mixed>>}
     */
    public function buildAll(User $user, ForecastPlan $plan, array $months): array
    {
        return $this->assemble($user, $plan->items, $plan->categoryEstimates, fn (ForecastItem $item): string => $item->destination_account_id ?? $item->account_id, $months, true, $this->transferRows->rows($plan->transferEstimates, null, $months));
    }

    /**
     * @param  Collection<int, ForecastItem>  $items
     * @param  list<CategoryEstimate>  $estimates
     * @param  Closure(ForecastItem): string  $sideOf  The account each line's amounts are signed from.
     * @param  list<string>  $months
     * @param  list<array<string, mixed>>  $estimatedTransfers  The usual transfers between the user's accounts, see TransferEstimateRows.
     * @return array{income: list<array<string, mixed>>, expenses: list<array<string, mixed>>, transfers: list<array<string, mixed>>}
     */
    private function assemble(User $user, Collection $items, array $estimates, Closure $sideOf, array $months, bool $perAccountEstimates, array $estimatedTransfers): array
    {
        $sides = $items->mapWithKeys(fn (ForecastItem $item): array => [$item->id => $sideOf($item)])->all();
        $occurrences = $this->occurrences($items, $months);
        $amounts = $this->amountsByItem($occurrences, $sides);
        $dates = $this->datesIn($occurrences, $months[0]);
        $matched = $this->matchedThisMonth($items, $sides, $months[0]);
        $counterparts = $this->counterpartNames($user, $items, $sides);
        $editing = $this->editDetails($user, $items, $occurrences, ForecastCalendar::horizonEnd($months[0].'-01', count($months)));
        $parents = $this->history->parentCategories($user);
        $groups = ['income' => [], 'expenses' => []];
        $transfers = [];

        foreach ($items as $item) {
            $row = [...$this->itemRow($item, $sides[$item->id], $amounts[$item->id] ?? [], $counterparts), ...$editing[$item->id]];
            $row['current'] = $this->currentCell($row['amounts'][$months[0]] ?? null, $matched[$item->id] ?? null, $dates[$item->id] ?? null);

            if ($item->destination_account_id !== null) {
                $transfers[] = $row;

                continue;
            }

            $section = $this->isIncome($item) ? 'income' : 'expenses';
            $groups[$section][$this->groupKey($item->category_id, $parents)]['items'][] = $row;
        }

        foreach ($this->estimatesByGroup($estimates) as $section => $byGroup) {
            foreach ($byGroup as $key => $byAccount) {
                foreach ($byAccount as $accountId => $accountEstimates) {
                    $row = $this->estimateRow($accountEstimates, $months);

                    if ($perAccountEstimates) {
                        $groups[$section][$key]['estimates'][] = ['accountId' => (string) $accountId, ...$row];
                    } else {
                        $groups[$section][$key]['estimate'] = $row;
                    }
                }
            }
        }

        $categories = $this->categories(array_merge(array_keys($groups['income']), array_keys($groups['expenses'])));

        return [
            'income' => $this->groupRows('income', $groups['income'], $categories, $perAccountEstimates),
            'expenses' => $this->groupRows('expenses', $groups['expenses'], $categories, $perAccountEstimates),
            'transfers' => $this->sortedByWeight([...$transfers, ...$estimatedTransfers]),
        ];
    }

    /**
     * The lines' occurrences over the months shown.
     *
     * @param  Collection<int, ForecastItem>  $items
     * @param  list<string>  $months
     * @return list<Occurrence>
     */
    private function occurrences(Collection $items, array $months): array
    {
        $from = ForecastCalendar::monthStart($months[0].'-01');

        return $this->expander->expand($items->map(ExpandableItem::fromModel(...)), $from, ForecastCalendar::horizonEnd($from, count($months)));
    }

    /**
     * What the edit sheet of each line needs: the line as the client engine
     * expands it, the occurrence each month shown holds, how it is
     * recognised, its last payments, and the update they suggest. Nothing
     * past `$until` is sent: versions and overrides after it are left out.
     *
     * @param  Collection<int, ForecastItem>  $items
     * @param  list<Occurrence>  $occurrences
     * @return array<string, array<string, mixed>> By line id.
     */
    private function editDetails(User $user, Collection $items, array $occurrences, string $until): array
    {
        $occurrenceDates = [];

        foreach ($occurrences as $occurrence) {
            $occurrenceDates[$occurrence->itemId][ForecastCalendar::monthKey($occurrence->date)] ??= $occurrence->occurrenceDate;
        }

        $payments = $this->recentPayments($items);
        $drifts = $this->drift->suggestions($items);
        $details = [];

        foreach ($items as $item) {
            $drift = $drifts[$item->id] ?? null;
            $driftFrom = $drift === null ? null : $this->drift->startsOn($user, $item, $drift);

            $details[$item->id] = [
                'accountId' => $item->account_id,
                'categoryId' => $item->category_id,
                'definition' => $this->definition($item, $until),
                'occurrenceDates' => $occurrenceDates[$item->id] ?? [],
                'matchText' => ForecastMatchRules::needle($item->match_rules_json),
                'amountTolerance' => $item->amount_tolerance,
                'dateWindowDays' => $item->date_window_days,
                'payments' => $payments[$item->id] ?? [],
                'drift' => $driftFrom === null || $driftFrom > $until ? null : ['amount' => $drift->amount, 'from' => $driftFrom],
            ];
        }

        return $details;
    }

    /**
     * The line in the shape resources/js/lib/forecast/expand-occurrences.ts
     * takes, up to `$until`.
     *
     * @return array<string, mixed>
     */
    private function definition(ForecastItem $item, string $until): array
    {
        $line = ExpandableItem::fromModel($item);

        return [
            'id' => $line->id,
            'accountId' => $line->accountId,
            'destinationAccountId' => $line->destinationAccountId,
            'frequency' => $line->frequency->value,
            'anchorDate' => $line->anchorDate,
            'endsOn' => $line->endsOn,
            'versions' => array_values(array_map(
                fn ($version): array => ['effectiveFrom' => $version->effectiveFrom, 'amount' => $version->amount],
                array_filter($line->versions, fn ($version): bool => $version->effectiveFrom <= $until),
            )),
            'overrides' => array_values(array_map(
                fn ($override): array => ['occurrenceDate' => $override->occurrenceDate, 'amount' => $override->amount, 'movedTo' => $override->movedTo, 'skipped' => $override->skipped],
                array_filter($line->overrides, fn ($override): bool => $override->occurrenceDate <= $until && ($override->movedTo ?? $override->occurrenceDate) <= $until),
            )),
        ];
    }

    /**
     * The latest payments of each line on its own account, newest first:
     * what was planned and what was paid.
     *
     * @param  Collection<int, ForecastItem>  $items
     * @return array<string, list<array{occurrenceDate: string, planned: int, real: int}>>
     */
    private function recentPayments(Collection $items): array
    {
        $byItem = [];
        $matches = ForecastMatch::query()
            ->whereIn('forecast_item_id', $items->modelKeys())
            ->whereHas('transaction')
            ->with('transaction:id,account_id,amount')
            ->orderByDesc('occurrence_date')
            ->get();
        $accounts = $items->pluck('account_id', 'id');

        foreach ($matches as $match) {
            $isOwnLeg = $match->transaction->account_id === $accounts[$match->forecast_item_id];

            if (! $isOwnLeg || count($byItem[$match->forecast_item_id] ?? []) >= self::RECENT_PAYMENTS) {
                continue;
            }

            $real = $match->transaction->amount;
            $byItem[$match->forecast_item_id][] = ['occurrenceDate' => $match->occurrence_date->toDateString(), 'planned' => $real - $match->deviation, 'real' => $real];
        }

        return $byItem;
    }

    /**
     * Each line's amount by month, from its side.
     *
     * @param  list<Occurrence>  $occurrences
     * @param  array<string, string>  $sides  The account each line is signed from, by line id.
     * @return array<string, array<string, int>> By line id, then month.
     */
    private function amountsByItem(array $occurrences, array $sides): array
    {
        $amounts = [];

        foreach ($occurrences as $occurrence) {
            $month = ForecastCalendar::monthKey($occurrence->date);
            $signed = self::signedFor($sides[$occurrence->itemId], $occurrence->accountId, $occurrence->amount);
            $amounts[$occurrence->itemId][$month] = ($amounts[$occurrence->itemId][$month] ?? 0) + $signed;
        }

        return $amounts;
    }

    /**
     * The day each line is due in the month, the first one when it has several.
     *
     * @param  list<Occurrence>  $occurrences
     * @return array<string, string> By line id.
     */
    private function datesIn(array $occurrences, string $month): array
    {
        $dates = [];

        foreach ($occurrences as $occurrence) {
            if (ForecastCalendar::monthKey($occurrence->date) === $month) {
                $dates[$occurrence->itemId] ??= $occurrence->date;
            }
        }

        return $dates;
    }

    /**
     * An amount booked on `$bookedOn`, as the account shown sees it: the
     * other leg of a transfer has the opposite sign.
     */
    private static function signedFor(string $accountId, string $bookedOn, int $amount): int
    {
        return $bookedOn === $accountId ? $amount : -$amount;
    }

    /**
     * What the transactions that paid each line this month add up to, from
     * the line's side. Lines nothing paid yet are left out.
     *
     * @param  Collection<int, ForecastItem>  $items
     * @param  array<string, string>  $sides  By line id.
     * @return array<string, int> By line id.
     */
    private function matchedThisMonth(Collection $items, array $sides, string $month): array
    {
        $matches = ForecastMatch::query()
            ->whereIn('forecast_item_id', $items->modelKeys())
            ->whereBetween('occurrence_date', [ForecastCalendar::monthStart($month.'-01'), ForecastCalendar::horizonEnd($month.'-01', 1)])
            ->with('transaction:id,account_id,amount')
            ->get();
        $real = [];

        foreach ($matches as $match) {
            if ($match->transaction === null) {
                continue;
            }

            $signed = self::signedFor($sides[$match->forecast_item_id], $match->transaction->account_id, $match->transaction->amount);
            $real[$match->forecast_item_id] = ($real[$match->forecast_item_id] ?? 0) + $signed;
        }

        return $real;
    }

    /**
     * The name of the other account of each transfer, by account id.
     *
     * @param  Collection<int, ForecastItem>  $items
     * @param  array<string, string>  $sides  By line id.
     * @return array<string, string>
     */
    private function counterpartNames(User $user, Collection $items, array $sides): array
    {
        $ids = $items
            ->filter(fn (ForecastItem $item): bool => $item->destination_account_id !== null)
            ->map(fn (ForecastItem $item): string => $item->account_id === $sides[$item->id] ? (string) $item->destination_account_id : $item->account_id)
            ->unique()
            ->values()
            ->all();

        return Account::query()->where('user_id', $user->id)->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array<string, int>  $amounts
     * @param  array<string, string>  $counterparts
     * @return array<string, mixed>
     */
    private function itemRow(ForecastItem $item, string $accountId, array $amounts, array $counterparts): array
    {
        $isTransfer = $item->destination_account_id !== null;
        $incoming = $item->destination_account_id === $accountId;

        return [
            'id' => $item->id,
            'name' => $item->name,
            'kind' => $isTransfer ? 'transfer' : $item->frequency->value,
            'source' => $item->source->value,
            'anchorDate' => $item->anchor_date->toDateString(),
            'direction' => $isTransfer ? ($incoming ? 'in' : 'out') : null,
            'counterpartyName' => $isTransfer ? ($counterparts[$incoming ? $item->account_id : (string) $item->destination_account_id] ?? null) : null,
            'amounts' => $amounts,
        ];
    }

    /**
     * @return array{planned: int|null, real: int|null, date: string|null, status: 'matched'|'pending'|'none'}
     */
    private function currentCell(?int $planned, ?int $real, ?string $date): array
    {
        $status = $real !== null ? 'matched' : ($planned !== null ? 'pending' : 'none');

        return ['planned' => $planned, 'real' => $real, 'date' => $date, 'status' => $status];
    }

    /**
     * Income is told apart by the sign of the line's latest amount.
     */
    private function isIncome(ForecastItem $item): bool
    {
        return ($item->versions->last()->amount ?? 0) > 0;
    }

    /**
     * @param  array<string, string>  $parents
     */
    private function groupKey(?string $categoryId, array $parents): string
    {
        return $categoryId === null ? self::OTHER : ($parents[$categoryId] ?? self::OTHER);
    }

    /**
     * @param  list<CategoryEstimate>  $estimates
     * @return array<'income'|'expenses', array<string, array<string, list<CategoryEstimate>>>> By section, group, then account id.
     */
    private function estimatesByGroup(array $estimates): array
    {
        $byGroup = [];

        foreach ($estimates as $estimate) {
            $byGroup[$estimate->isIncome() ? 'income' : 'expenses'][$estimate->categoryId ?? self::OTHER][$estimate->accountId][] = $estimate;
        }

        return $byGroup;
    }

    /**
     * The estimate of a group: signed amounts by month (income positive,
     * spend negative), its `kind`, and what the month in progress has
     * already spent or received of it (`spent`, positive).
     *
     * @param  list<CategoryEstimate>  $estimates
     * @param  list<string>  $months
     * @return array<string, mixed>
     */
    private function estimateRow(array $estimates, array $months): array
    {
        $shown = array_flip($months);
        $amounts = [];
        $current = null;
        $basis = null;

        foreach ($estimates as $estimate) {
            if (! isset($shown[$estimate->month])) {
                continue;
            }

            if ($estimate->month === $months[0]) {
                $current = ['spent' => $estimate->spent, 'planned' => $estimate->planned ?? $estimate->amount, 'left' => $estimate->amount];
            } else {
                $amounts[$estimate->month] = $estimate->signedAmount();
            }

            $basis ??= ['basis' => $estimate->basis->value, 'budgetAmount' => $estimate->budgetAmount];
        }

        return [
            'kind' => ($estimates[0] ?? null)?->kind->value ?? ForecastEstimateKind::Expense->value,
            ...($basis ?? ['basis' => null, 'budgetAmount' => null]),
            'amounts' => $amounts,
            'current' => $current,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, Category>
     */
    private function categories(array $keys): array
    {
        return Category::query()
            ->whereIn('id', array_diff($keys, [self::OTHER]))
            ->get(['id', 'name', 'icon', 'color'])
            ->keyBy('id')
            ->all();
    }

    /**
     * The groups heaviest first, the uncategorized one last.
     *
     * @param  'income'|'expenses'  $section
     * @param  array<string, array{items?: list<array<string, mixed>>, estimate?: array<string, mixed>, estimates?: list<array<string, mixed>>}>  $groups
     * @param  array<string, Category>  $categories
     * @return list<array<string, mixed>>
     */
    private function groupRows(string $section, array $groups, array $categories, bool $perAccountEstimates): array
    {
        $rows = [];

        foreach ($groups as $key => $group) {
            $key = (string) $key;
            $rows[] = [
                'key' => $key,
                'section' => $section,
                'categoryId' => $key === self::OTHER ? null : $key,
                'name' => $categories[$key]->name ?? null,
                'icon' => $categories[$key]->icon ?? null,
                'color' => $categories[$key]->color ?? null,
                'items' => $this->sortedByWeight($group['items'] ?? []),
                ...($perAccountEstimates
                    ? ['estimates' => $this->sortedByWeight($group['estimates'] ?? [])]
                    : ['estimate' => $group['estimate'] ?? null]),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['key'] === self::OTHER, $this->weight($b)] <=> [$b['key'] === self::OTHER, $this->weight($a)]);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortedByWeight(array $rows): array
    {
        usort($rows, fn (array $a, array $b): int => $this->weight($b) <=> $this->weight($a));

        return $rows;
    }

    /**
     * How much money a row or a group moves over the months shown.
     *
     * @param  array<string, mixed>  $row
     */
    private function weight(array $row): int
    {
        $total = array_sum(array_map(abs(...), $row['amounts'] ?? []));
        $total += array_sum(array_map(abs(...), $row['estimate']['amounts'] ?? []));

        foreach ([...$row['items'] ?? [], ...$row['estimates'] ?? []] as $item) {
            $total += $this->weight($item);
        }

        return $total;
    }
}
