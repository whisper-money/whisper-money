<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryType;
use App\Http\Controllers\Api\Concerns\ConvertsTransactionCurrency;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CategoryMonthlyBreakdownController extends Controller
{
    use ConvertsTransactionCurrency;

    /**
     * The rolling window shown on the chart, in months (including the current).
     */
    private const MONTHS = 12;

    /**
     * The richest children get their own stacked segment; everything past this
     * folds into a single "Other" segment so the stack stays legible.
     */
    private const TOP_CHILDREN = 6;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
    ) {}

    public function __invoke(Request $request, Category $category): JsonResponse
    {
        $user = $request->user();

        abort_unless($category->user_id === $user->id, 403);

        $currency = $user->currency_code;
        $start = Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);

        $parentMap = Category::query()
            ->where('user_id', $user->id)
            ->pluck('parent_id', 'id')
            ->all();

        $children = Category::query()
            ->where('user_id', $user->id)
            ->where('parent_id', $category->id)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $subtreeIds = array_values(array_filter(
            array_keys($parentMap),
            fn (string $id): bool => $this->belongsToSubtree($id, $category->id, $parentMap),
        ));

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereIn('category_id', $subtreeIds)
            ->where('transaction_date', '>=', $start->toDateString())
            ->with('account')
            ->get();

        $this->preloadExchangeRates($transactions, $currency);

        // Outflow categories store spending as negative amounts; flip the sign so
        // the expected direction reads as a positive bar. A transaction that runs
        // against the grain (a refund on an expense category) then nets the bar
        // down and can dip below zero, which is truthful.
        $orientation = $category->type === CategoryType::Income ? 1 : -1;
        $hasChildren = $children->isNotEmpty();

        $buckets = [];
        $childTotals = [];
        $directTotal = 0;

        foreach ($transactions as $transaction) {
            $amount = $this->convertTransactionAmount($transaction, $currency) * $orientation;
            $monthKey = $transaction->transaction_date->format('Y-m');
            $segment = $this->segmentFor($transaction->category_id, $category->id, $parentMap, $hasChildren);

            $buckets[$monthKey][$segment] = ($buckets[$monthKey][$segment] ?? 0) + $amount;

            if ($segment === 'direct') {
                $directTotal += $amount;
            } elseif ($hasChildren) {
                $childTotals[$segment] = ($childTotals[$segment] ?? 0) + $amount;
            }
        }

        $series = $this->buildSeries($category, $children, $childTotals, $directTotal, $hasChildren);
        $months = $this->buildMonths($start, $buckets, $series);

        return response()
            ->json([
                'currency' => $currency,
                'category' => ['id' => $category->id, 'name' => $category->name],
                'series' => $series,
                'months' => $months,
                'summary' => $this->summarize($months),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Headline figures for the window: the average spent per month and the
     * trend, measured as the change between the average of the most recent half
     * and the average of the earlier half. The trend is null when the earlier
     * half is empty, since there is no baseline to compare against.
     *
     * @param  array<int, array<string, int|string>>  $months
     * @return array{average_per_month: int, trend_percentage: float|null}
     */
    private function summarize(array $months): array
    {
        $totals = array_map(function (array $point): int {
            $total = 0;

            foreach ($point as $key => $value) {
                if ($key !== 'key') {
                    $total += (int) $value;
                }
            }

            return $total;
        }, $months);

        $count = count($totals);
        $half = intdiv($count, 2);
        $earlier = array_slice($totals, 0, $half);
        $recent = array_slice($totals, $count - $half);

        $earlierAverage = $earlier === [] ? 0 : array_sum($earlier) / count($earlier);
        $recentAverage = $recent === [] ? 0 : array_sum($recent) / count($recent);

        return [
            'average_per_month' => $count > 0 ? (int) round(array_sum($totals) / $count) : 0,
            'trend_percentage' => $earlierAverage != 0
                ? round((($recentAverage - $earlierAverage) / abs($earlierAverage)) * 100, 1)
                : null,
        ];
    }

    /**
     * Whether a category sits within the chosen category's subtree (itself or a
     * descendant, bounded by the tree's maximum depth).
     *
     * @param  array<string, ?string>  $parentMap
     */
    private function belongsToSubtree(string $categoryId, string $rootId, array $parentMap): bool
    {
        $current = $categoryId;
        $guard = 0;

        while ($current !== null && $guard++ <= Category::MAX_DEPTH) {
            if ($current === $rootId) {
                return true;
            }

            $current = $parentMap[$current] ?? null;
        }

        return false;
    }

    /**
     * Resolve the stacked segment a transaction's category contributes to: the
     * chosen category's own spend ("direct"), or the immediate child whose
     * subtree the category lives in. A leaf category has no children, so all of
     * its spend collapses onto a single segment keyed by the category itself.
     *
     * @param  array<string, ?string>  $parentMap
     */
    private function segmentFor(?string $categoryId, string $rootId, array $parentMap, bool $hasChildren): string
    {
        if (! $hasChildren) {
            return $rootId;
        }

        if ($categoryId === null || $categoryId === $rootId) {
            return 'direct';
        }

        $current = $categoryId;
        $guard = 0;

        while ($current !== null && $guard++ <= Category::MAX_DEPTH) {
            $parent = $parentMap[$current] ?? null;

            if ($parent === $rootId) {
                return $current;
            }

            $current = $parent;
        }

        return 'direct';
    }

    /**
     * Order the segments richest-first, cap the children at the top N, and fold
     * the remainder into an "Other" segment. A leaf category yields a single
     * segment named after the category; a parent yields its children plus the
     * optional "Other" and "Direct" segments.
     *
     * @param  Collection<string, Category>  $children
     * @param  array<string, int>  $childTotals
     * @return array<int, array{key: string, label: string}>
     */
    private function buildSeries(Category $category, Collection $children, array $childTotals, int $directTotal, bool $hasChildren): array
    {
        if (! $hasChildren) {
            return [['key' => $category->id, 'label' => $category->name]];
        }

        $ranked = collect($childTotals)
            ->sortByDesc(fn (int $total): int => $total)
            ->keys()
            ->all();

        $series = [];

        foreach (array_slice($ranked, 0, self::TOP_CHILDREN) as $childId) {
            $series[] = ['key' => $childId, 'label' => $children->get($childId)->name];
        }

        if (count($ranked) > self::TOP_CHILDREN) {
            $series[] = ['key' => 'other', 'label' => __('Other')];
        }

        if ($directTotal !== 0) {
            $series[] = ['key' => 'direct', 'label' => __('Direct')];
        }

        return $series;
    }

    /**
     * Build a point per month across the whole window, filling gaps with zero so
     * the trend is never broken. Segments outside the kept set (overflow
     * children) are summed into the "Other" segment.
     *
     * @param  array<string, array<string, int>>  $buckets
     * @param  array<int, array{key: string, label: string}>  $series
     * @return array<int, array<string, int|string>>
     */
    private function buildMonths(Carbon $start, array $buckets, array $series): array
    {
        $seriesKeys = array_column($series, 'key');
        $seriesKeySet = array_flip($seriesKeys);
        $hasOther = isset($seriesKeySet['other']);

        $months = [];
        $cursor = $start->copy();

        for ($index = 0; $index < self::MONTHS; $index++) {
            $monthKey = $cursor->format('Y-m');
            $point = ['key' => $monthKey];

            foreach ($seriesKeys as $seriesKey) {
                $point[$seriesKey] = 0;
            }

            foreach (($buckets[$monthKey] ?? []) as $segment => $amount) {
                $target = isset($seriesKeySet[$segment]) ? $segment : ($hasOther ? 'other' : null);

                if ($target !== null) {
                    $point[$target] += $amount;
                }
            }

            $months[] = $point;
            $cursor->addMonth();
        }

        return $months;
    }
}
