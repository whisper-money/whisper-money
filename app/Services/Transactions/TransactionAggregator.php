<?php

namespace App\Services\Transactions;

use App\Enums\CategoryType;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Rules\StorableDate;
use App\Services\CategoryTree;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Adds a user's transactions up in SQL, grouped by up to two dimensions, for
 * `GET /api/v1/analytics/aggregate` and the aggregate_transactions MCP tool.
 *
 * Every bucket is also split by currency, so amounts in different currencies
 * are never added together, and amounts stay in that currency's minor units.
 * Like the other analytics figures, each amount is the user's share of the
 * account ({@see Transaction::OWNED_AMOUNT_SQL}) and an archived account stops
 * counting from the day it was archived. Only the caller's own transactions in
 * the space are read, through {@see TransactionSearch}.
 *
 * Grouped by a period, every period of the range is returned, with zeros where
 * nothing was booked, so a chart has no gaps.
 */
class TransactionAggregator
{
    /** @var list<string> */
    public const TIME_DIMENSIONS = ['day', 'week', 'month', 'year'];

    /** @var list<string> */
    public const DIMENSIONS = [...self::TIME_DIMENSIONS, 'category', 'label', 'account', 'type'];

    /** @var list<string> */
    public const METRICS = ['sum', 'count', 'avg', 'min', 'max'];

    /** @var list<string> */
    public const DEFAULT_METRICS = ['sum', 'count'];

    public const MAX_DIMENSIONS = 2;

    /**
     * The most buckets one response returns. A request that would return more
     * is refused rather than cut short, so a chart never silently misses data.
     */
    public const MAX_BUCKETS = 1000;

    public const MAX_IDS = 100;

    /** @var list<string> */
    private const LIST_PARAMETERS = ['group_by', 'metrics', 'types', 'category_ids', 'label_ids', 'account_ids'];

    /**
     * The type of a row as the cashflow reads it: its category's type, or the
     * sign of the amount when it has none (or its category was deleted).
     */
    private const TYPE_SQL = "case when categories.id is not null then categories.type when transactions.amount < 0 then 'expense' else 'income' end";

    private const CURRENCY_SQL = "upper(coalesce(nullif(transactions.currency_code, ''), accounts.currency_code))";

    /**
     * The SQL selecting each dimension, keyed by the column it comes back as.
     *
     * @var array<string, array<string, string>>
     */
    private const DIMENSION_COLUMNS = [
        'day' => ['day' => "date_format(transactions.transaction_date, '%Y-%m-%d')"],
        'week' => ['week' => "date_format(date_sub(transactions.transaction_date, interval weekday(transactions.transaction_date) day), '%Y-%m-%d')"],
        'month' => ['month' => "date_format(transactions.transaction_date, '%Y-%m')"],
        'year' => ['year' => "date_format(transactions.transaction_date, '%Y')"],
        'category' => ['category_id' => 'categories.id', 'category_name' => 'categories.name'],
        'label' => ['label_id' => 'grouped_labels.label_id', 'label_name' => 'grouped_labels.label_name'],
        'account' => ['account_id' => 'transactions.account_id', 'account_name' => 'accounts.name'],
        'type' => ['type' => self::TYPE_SQL],
    ];

    private const METRIC_SQL = [
        'sum' => 'sum('.Transaction::OWNED_AMOUNT_SQL.')',
        'count' => 'count(*)',
        'avg' => 'round(avg('.Transaction::OWNED_AMOUNT_SQL.'))',
        'min' => 'min('.Transaction::OWNED_AMOUNT_SQL.')',
        'max' => 'max('.Transaction::OWNED_AMOUNT_SQL.')',
    ];

    /**
     * The metrics an empty bucket reads as zero; the others are null there.
     *
     * @var list<string>
     */
    private const ZERO_WHEN_EMPTY = ['sum', 'count'];

    public function __construct(private TransactionSearch $search, private CategoryTree $tree) {}

    /**
     * The rules every caller validates the parameters with.
     *
     * @return array<string, array<mixed>>
     */
    public static function rules(): array
    {
        $ids = ['sometimes', 'array', 'max:'.self::MAX_IDS];

        return [
            'group_by' => ['sometimes', 'array', 'max:'.self::MAX_DIMENSIONS, self::oneTimeDimension()],
            'group_by.*' => ['string', 'distinct', Rule::in(self::DIMENSIONS)],
            'metrics' => ['sometimes', 'array', 'min:1'],
            'metrics.*' => ['string', 'distinct', Rule::in(self::METRICS)],
            'from' => ['required', 'date_format:Y-m-d', new StorableDate],
            'to' => ['required', 'date_format:Y-m-d', new StorableDate, 'after_or_equal:from'],
            'category_ids' => $ids,
            'category_ids.*' => ['string'],
            'include_subcategories' => ['sometimes', 'boolean'],
            'label_ids' => $ids,
            'label_ids.*' => ['string'],
            'account_ids' => $ids,
            'account_ids.*' => ['string'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', 'distinct', Rule::enum(CategoryType::class)],
            'query' => ['sometimes', 'string', 'max:200'],
        ];
    }

    /**
     * The parameters as sent, with every list given as a comma-separated
     * string (`group_by=month,category`) split into an array and a `true` /
     * `false` string read as a boolean, so a query string and an agent can
     * write them either way. Only the changed keys are returned.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $normalized = [];

        foreach (self::LIST_PARAMETERS as $field) {
            if (is_string($input[$field] ?? null)) {
                $normalized[$field] = array_values(array_filter(array_map('trim', explode(',', $input[$field])), fn (string $item): bool => $item !== ''));
            }
        }

        $includeSubcategories = $input['include_subcategories'] ?? null;

        if (is_string($includeSubcategories) && in_array(strtolower($includeSubcategories), ['true', 'false'], true)) {
            $normalized['include_subcategories'] = strtolower($includeSubcategories) === 'true';
        }

        return $normalized;
    }

    /**
     * $user's own transactions of $space added up as $parameters ask, already
     * validated with {@see self::rules()} and with every id checked to be the
     * user's own in the space.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{group_by: list<string>, metrics: list<string>, from: string, to: string, buckets: list<array<string, int|string|null>>}
     *
     * @throws ValidationException when the result has more than {@see self::MAX_BUCKETS} buckets
     */
    public function aggregate(User $user, Space $space, array $parameters): array
    {
        $groupBy = array_values($parameters['group_by'] ?? []);
        $metrics = array_values($parameters['metrics'] ?? self::DEFAULT_METRICS);
        $from = CarbonImmutable::parse($parameters['from']);
        $to = CarbonImmutable::parse($parameters['to']);

        $timeDimension = collect($groupBy)->first(fn (string $dimension): bool => in_array($dimension, self::TIME_DIMENSIONS, true));
        $periods = $timeDimension === null ? [] : $this->periods($timeDimension, $from, $to);

        $buckets = $this->query($user, $space, $parameters, $groupBy, $metrics)
            ->limit(self::MAX_BUCKETS + 1)
            ->toBase()
            ->get()
            ->map(fn (stdClass $row): array => $this->bucket($row, $groupBy, $metrics))
            ->all();

        if ($timeDimension !== null) {
            $buckets = $this->fillPeriods($buckets, $timeDimension, $periods, $groupBy, $metrics, $user);
        }

        if (count($buckets) > self::MAX_BUCKETS) {
            throw $this->tooManyBuckets();
        }

        return [
            'group_by' => $groupBy,
            'metrics' => $metrics,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'buckets' => array_values($buckets),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  list<string>  $groupBy
     * @param  list<string>  $metrics
     * @return Builder<Transaction>
     */
    private function query(User $user, Space $space, array $parameters, array $groupBy, array $metrics): Builder
    {
        $labelIds = array_values($parameters['label_ids'] ?? []);
        $types = array_values($parameters['types'] ?? []);

        $query = $this->search->query($user, $space, [
            'query' => $parameters['query'] ?? '',
            'account_ids' => array_values($parameters['account_ids'] ?? []),
            'category_ids' => $this->categoryIds($user, $parameters),
            'label_ids' => $labelIds,
            'from' => $parameters['from'],
            'to' => $parameters['to'],
        ])
            ->joinOwningAccount()
            ->withoutArchivedAccountActivity()
            ->leftJoin('categories', fn (JoinClause $join) => $join
                ->on('categories.id', '=', 'transactions.category_id')
                ->whereNull('categories.deleted_at'))
            ->when($types !== [], fn (Builder $query) => $query->whereIn(DB::raw(self::TYPE_SQL), $types))
            ->when(in_array('label', $groupBy, true), fn (Builder $query) => $query->leftJoinSub(
                $this->labelsOfTransactions($labelIds),
                'grouped_labels',
                'grouped_labels.transaction_id',
                '=',
                'transactions.id',
            ))
            ->select([]);

        $columns = [];

        foreach ($groupBy as $dimension) {
            $columns = [...$columns, ...self::DIMENSION_COLUMNS[$dimension]];
        }

        $columns['currency'] = self::CURRENCY_SQL;

        // Grouped by the expression, not the alias: MySQL reads a bare name in
        // GROUP BY as a column of the joined tables first, so `type` would
        // group by accounts.type and `category_id` by the transaction's own.
        foreach ($columns as $alias => $sql) {
            $query->selectRaw("{$sql} as {$alias}")->groupByRaw($sql);
        }

        // By name rather than id, so a chart lists its series alphabetically.
        foreach (array_keys($columns) as $alias) {
            if (! str_ends_with($alias, '_id')) {
                $query->orderBy($alias);
            }
        }

        foreach ($metrics as $metric) {
            $query->selectRaw(self::METRIC_SQL[$metric]." as {$metric}");
        }

        return $query;
    }

    /**
     * Every live label on each transaction, one row per pair. Narrowed to
     * $labelIds when the transactions are filtered by label, so grouping by
     * label only shows the labels asked for.
     *
     * @param  list<string>  $labelIds
     */
    private function labelsOfTransactions(array $labelIds): QueryBuilder
    {
        return DB::table('label_transaction')
            ->join('labels', 'labels.id', '=', 'label_transaction.label_id')
            ->whereNull('labels.deleted_at')
            ->when($labelIds !== [], fn (QueryBuilder $query) => $query->whereIn('labels.id', $labelIds))
            ->select(['label_transaction.transaction_id', 'labels.id as label_id', 'labels.name as label_name']);
    }

    /**
     * The categories to filter by, each with its subcategories unless
     * `include_subcategories` is false.
     *
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    private function categoryIds(User $user, array $parameters): array
    {
        $ids = array_values($parameters['category_ids'] ?? []);

        if ($ids === [] || ! filter_var($parameters['include_subcategories'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return $ids;
        }

        return array_values($this->tree->expand($user->id, $ids));
    }

    /**
     * @param  list<string>  $groupBy
     * @param  list<string>  $metrics
     * @return array<string, int|string|null>
     */
    private function bucket(stdClass $row, array $groupBy, array $metrics): array
    {
        $bucket = [];

        foreach ($groupBy as $dimension) {
            foreach (array_keys(self::DIMENSION_COLUMNS[$dimension]) as $column) {
                $bucket[$column] = $row->{$column} === null ? null : (string) $row->{$column};
            }
        }

        $bucket['currency'] = (string) $row->currency;

        foreach ($metrics as $metric) {
            $bucket[$metric] = $row->{$metric} === null ? null : (int) $row->{$metric};
        }

        return $bucket;
    }

    /**
     * Every period of the range for every series the rows hold (the other
     * dimension's value and the currency), with zeros where there were none.
     * With nothing booked at all and no other dimension, the periods come back
     * as zeros in the user's currency.
     *
     * @param  list<array<string, int|string|null>>  $buckets
     * @param  list<string>  $periods
     * @param  list<string>  $groupBy
     * @param  list<string>  $metrics
     * @return list<array<string, int|string|null>>
     */
    private function fillPeriods(array $buckets, string $timeDimension, array $periods, array $groupBy, array $metrics, User $user): array
    {
        $series = [];
        $found = [];

        foreach ($buckets as $bucket) {
            $seriesOf = array_diff_key($bucket, [$timeDimension => true], array_flip($metrics));
            $key = json_encode($seriesOf);
            $series[$key] ??= $seriesOf;
            $found[$key][$bucket[$timeDimension]] = $bucket;
        }

        if ($series === [] && count($groupBy) === 1) {
            $series['default'] = ['currency' => $user->currency_code ?? 'USD'];
        }

        if (count($periods) * max(count($series), 1) > self::MAX_BUCKETS) {
            throw $this->tooManyBuckets();
        }

        $filled = [];

        foreach ($periods as $period) {
            foreach ($series as $key => $seriesOf) {
                $filled[] = $found[$key][$period] ?? $this->emptyBucket($timeDimension, $period, $groupBy, $seriesOf, $metrics);
            }
        }

        return $filled;
    }

    /**
     * @param  list<string>  $groupBy
     * @param  array<string, int|string|null>  $seriesOf
     * @param  list<string>  $metrics
     * @return array<string, int|string|null>
     */
    private function emptyBucket(string $timeDimension, string $period, array $groupBy, array $seriesOf, array $metrics): array
    {
        $bucket = [];

        foreach ($groupBy as $dimension) {
            foreach (array_keys(self::DIMENSION_COLUMNS[$dimension]) as $column) {
                $bucket[$column] = $dimension === $timeDimension ? $period : $seriesOf[$column];
            }
        }

        $bucket['currency'] = $seriesOf['currency'];

        foreach ($metrics as $metric) {
            $bucket[$metric] = in_array($metric, self::ZERO_WHEN_EMPTY, true) ? 0 : null;
        }

        return $bucket;
    }

    /**
     * Every period of $dimension the range touches, as its bucket reads it: a
     * week is named by its Monday.
     *
     * @return list<string>
     */
    private function periods(string $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $step, $format] = match ($dimension) {
            'day' => [$from, '1 day', 'Y-m-d'],
            'week' => [$from->startOfWeek(CarbonImmutable::MONDAY), '1 week', 'Y-m-d'],
            'month' => [$from->startOfMonth(), '1 month', 'Y-m'],
            default => [$from->startOfYear(), '1 year', 'Y'],
        };

        $periods = [];

        for ($period = $start; $period->lessThanOrEqualTo($to); $period = $period->add($step)) {
            if (count($periods) === self::MAX_BUCKETS) {
                throw $this->tooManyBuckets();
            }

            $periods[] = $period->format($format);
        }

        return $periods;
    }

    private function tooManyBuckets(): ValidationException
    {
        return ValidationException::withMessages([
            'group_by' => 'This aggregation returns more than '.self::MAX_BUCKETS.' buckets. Narrow the date range, group by a longer period or by fewer dimensions, or filter the transactions.',
        ]);
    }

    /**
     * At most one of the dimensions may be a period: grouping a day by its
     * month adds nothing.
     */
    private static function oneTimeDimension(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_array($value) && count(array_intersect($value, self::TIME_DIMENSIONS)) > 1) {
                $fail('Group by at most one period: day, week, month or year.');
            }
        };
    }
}
