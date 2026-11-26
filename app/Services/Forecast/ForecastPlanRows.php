<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\Category;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\User;
use App\Services\Forecast\Data\CategoryEstimate;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\Data\ForecastPlan;
use App\Services\Forecast\Data\Occurrence;
use Illuminate\Database\Eloquent\Collection;

/**
 * The rows of the forecast plan of one account, as the page shows them: the
 * income and the spend grouped under the user's parent categories, each with
 * its concrete lines and its estimated rest, and the transfers apart. Amounts
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

    public function __construct(
        private readonly OccurrenceExpander $expander,
        private readonly ForecastHistory $history,
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
        $occurrences = $this->occurrences($items, $months);
        $amounts = $this->amountsByItem($occurrences, $account->id);
        $dates = $this->datesIn($occurrences, $months[0]);
        $matched = $this->matchedThisMonth($items, $account->id, $months[0]);
        $counterparts = $this->counterpartNames($user, $items, $account->id);
        $parents = $this->history->parentCategories($user);
        $groups = ['income' => [], 'expenses' => []];
        $transfers = [];

        foreach ($items as $item) {
            $row = $this->itemRow($item, $account->id, $amounts[$item->id] ?? [], $counterparts);
            $row['current'] = $this->currentCell($row['amounts'][$months[0]] ?? null, $matched[$item->id] ?? null, $dates[$item->id] ?? null);

            if ($item->destination_account_id !== null) {
                $transfers[] = $row;

                continue;
            }

            $section = $this->isIncome($item) ? 'income' : 'expenses';
            $groups[$section][$this->groupKey($item->category_id, $parents)]['items'][] = $row;
        }

        foreach ($this->estimatesByGroup($plan->categoryEstimates, $account->id) as $key => $estimates) {
            $groups['expenses'][$key]['estimate'] = $this->estimateRow($estimates, $months);
        }

        $categories = $this->categories(array_merge(array_keys($groups['income']), array_keys($groups['expenses'])));

        return [
            'income' => $this->groupRows($groups['income'], $categories),
            'expenses' => $this->groupRows($groups['expenses'], $categories),
            'transfers' => $this->sortedByWeight($transfers),
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
     * Each line's amount by month, from the account's side.
     *
     * @param  list<Occurrence>  $occurrences
     * @return array<string, array<string, int>> By line id, then month.
     */
    private function amountsByItem(array $occurrences, string $accountId): array
    {
        $amounts = [];

        foreach ($occurrences as $occurrence) {
            $month = ForecastCalendar::monthKey($occurrence->date);
            $signed = self::signedFor($accountId, $occurrence->accountId, $occurrence->amount);
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
     * the account's side. Lines nothing paid yet are left out.
     *
     * @param  Collection<int, ForecastItem>  $items
     * @return array<string, int> By line id.
     */
    private function matchedThisMonth(Collection $items, string $accountId, string $month): array
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

            $signed = self::signedFor($accountId, $match->transaction->account_id, $match->transaction->amount);
            $real[$match->forecast_item_id] = ($real[$match->forecast_item_id] ?? 0) + $signed;
        }

        return $real;
    }

    /**
     * The name of the other account of each transfer, by account id.
     *
     * @param  Collection<int, ForecastItem>  $items
     * @return array<string, string>
     */
    private function counterpartNames(User $user, Collection $items, string $accountId): array
    {
        $ids = $items
            ->filter(fn (ForecastItem $item): bool => $item->destination_account_id !== null)
            ->map(fn (ForecastItem $item): string => $item->account_id === $accountId ? (string) $item->destination_account_id : $item->account_id)
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
     * @return array<string, list<CategoryEstimate>>
     */
    private function estimatesByGroup(array $estimates, string $accountId): array
    {
        $byGroup = [];

        foreach ($estimates as $estimate) {
            if ($estimate->accountId === $accountId) {
                $byGroup[$estimate->categoryId ?? self::OTHER][] = $estimate;
            }
        }

        return $byGroup;
    }

    /**
     * The estimated rest of a group: negative amounts by month, and what the
     * month in progress has spent of it so far.
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
                $amounts[$estimate->month] = -$estimate->amount;
            }

            $basis ??= ['basis' => $estimate->basis->value, 'budgetAmount' => $estimate->budgetAmount];
        }

        return [
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
     * @param  array<string, array{items?: list<array<string, mixed>>, estimate?: array<string, mixed>}>  $groups
     * @param  array<string, Category>  $categories
     * @return list<array<string, mixed>>
     */
    private function groupRows(array $groups, array $categories): array
    {
        $rows = [];

        foreach ($groups as $key => $group) {
            $key = (string) $key;
            $rows[] = [
                'key' => $key,
                'categoryId' => $key === self::OTHER ? null : $key,
                'name' => $categories[$key]->name ?? null,
                'icon' => $categories[$key]->icon ?? null,
                'color' => $categories[$key]->color ?? null,
                'items' => $this->sortedByWeight($group['items'] ?? []),
                'estimate' => $group['estimate'] ?? null,
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

        foreach ($row['items'] ?? [] as $item) {
            $total += $this->weight($item);
        }

        return $total;
    }
}
