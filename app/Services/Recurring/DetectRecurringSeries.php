<?php

namespace App\Services\Recurring;

use App\Data\CadenceMatch;
use App\Enums\RecurringCadence;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\RecurringSeries;
use App\Models\RecurringSeriesTransaction;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\EffectiveTransactionPostings;
use App\Support\Statistics;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Finds charges that repeat in a user's ledger and reconciles them into series.
 *
 * Detection is descriptive, never generative: it groups transactions that
 * already exist and never writes to the ledger. Identity planning is pure; the
 * write path applies one plan atomically after the shared user lock is held.
 */
class DetectRecurringSeries
{
    private const DETECTION_LOCK_TTL_SECONDS = 900;

    public function __construct(
        private readonly MerchantKeyBuilder $merchantKeys,
        private readonly CadenceClassifier $classifier,
        private readonly EffectiveTransactionPostings $postings,
        private readonly ReconcileSeriesIdentity $identities,
    ) {}

    /**
     * Rebuild every series for a user, in each space where they hold
     * transactions or existing series.
     *
     * @return int the number of series applied
     */
    public function forUserEverywhere(User $user): int
    {
        return $this->withDetectionLock($user, function () use ($user): int {
            $spaceIds = $this->spaceIdsForUser($user);

            if ($spaceIds->isEmpty()) {
                return 0;
            }

            return (int) Space::query()
                ->whereIn('id', $spaceIds)
                ->get()
                ->sum(fn (Space $space): int => $this->forUserUnlocked($user, $space));
        });
    }

    /**
     * Rebuild every series for one user in one space.
     *
     * @api Called by focused recurring tests and manual rescan integrations.
     *
     * @return int the number of series applied
     */
    public function forUser(User $user, ?Space $space = null): int
    {
        return $this->withDetectionLock($user, function () use ($user, $space): int {
            return $this->forUserUnlocked($user, $space);
        });
    }

    /**
     * Calculate every space's plan without saving series, pivots, execution
     * status, notifications or ledger rows.
     *
     * @return array{user_id: string, spaces: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function previewForUserEverywhere(User $user): array
    {
        $spaceIds = $this->spaceIdsForUser($user);
        $spaces = Space::query()
            ->whereIn('id', $spaceIds)
            ->get()
            ->map(fn (Space $space): array => $this->previewForUser($user, $space))
            ->values()
            ->all();

        return [
            'user_id' => (string) $user->id,
            'spaces' => $spaces,
            'totals' => $this->sumPreviewReports($spaces),
        ];
    }

    /**
     * Calculate one space's pure reconciliation report.
     *
     * @return array<string, mixed>
     */
    public function previewForUser(User $user, ?Space $space = null): array
    {
        $space ??= $this->readOnlySpaceForUser($user);

        if (! $space instanceof Space) {
            return $this->emptyPreview($user);
        }

        return $this->previewReport($this->buildPlan($user, $space));
    }

    /**
     * Lock key shared by the command and queued job.
     *
     * @api
     */
    public static function lockKeyForUser(string $userId): string
    {
        return "recurring_detection_lock_{$userId}";
    }

    private function forUserUnlocked(User $user, ?Space $space): int
    {
        $space ??= $user->activeSpace();

        return $this->persist($this->buildPlan($user, $space));
    }

    /**
     * @return Collection<int, string>
     */
    private function spaceIdsForUser(User $user): Collection
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->distinct()
            ->pluck('space_id')
            ->merge(
                RecurringSeries::query()
                    ->where('user_id', $user->id)
                    ->distinct()
                    ->pluck('space_id'),
            )
            ->filter()
            ->unique()
            ->values();
    }

    private function readOnlySpaceForUser(User $user): ?Space
    {
        if ($user->current_space_id !== null) {
            $space = Space::query()->find($user->current_space_id);

            if ($space instanceof Space && $space->hasMember($user)) {
                return $space;
            }
        }

        return $user->accessibleSpaces()->first();
    }

    /** @return array<string, mixed> */
    private function emptyPreview(User $user): array
    {
        return [
            'user_id' => (string) $user->id,
            'space_id' => null,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'merged' => 0,
            'review_required' => 0,
            'delta' => [],
            'candidates' => [],
        ];
    }

    /**
     * @param  Closure(): int  $callback
     */
    private function withDetectionLock(User $user, Closure $callback): int
    {
        $lock = Cache::lock(self::lockKeyForUser((string) $user->id), self::DETECTION_LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException("Recurring detection already running for user {$user->id}.");
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{user: User, space: Space, candidates: list<array<string, mixed>>, plans: list<array{candidate: array<string, mixed>, resolution: array<string, mixed>}>, existing: EloquentCollection<int, RecurringSeries>, protected_existing_ids: list<string>}
     */
    private function buildPlan(User $user, Space $space): array
    {
        $since = CarbonImmutable::today()->subMonths((int) config('recurring.lookback_months'));
        $descriptions = $this->candidates($user, $space, $since)->pluck('description');
        $documentFrequency = $this->merchantKeys->documentFrequency($descriptions);
        $noiseThreshold = $descriptions->count() * (float) config('recurring.noise_token_fraction');
        $candidates = $this->classify(
            $this->group($user, $space, $since, $documentFrequency, $noiseThreshold),
        );
        $categories = $this->effectiveCategories($candidates);

        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['category_id'] = $categories[$this->candidateIdentity($candidate)] ?? null;
        }

        $existing = RecurringSeries::withTrashed()
            ->where('user_id', $user->id)
            ->where('space_id', $space->id)
            ->with(['transactions:id'])
            ->orderBy('id')
            ->get();
        $plans = [];
        $protectedExistingIds = [];

        foreach ($candidates as $candidate) {
            $plans[] = [
                'candidate' => $candidate,
                'resolution' => $this->identities->planCandidate($candidate, $existing),
            ];
        }

        $plans = $this->oneCandidatePerSeries($plans);

        foreach ($plans as $item) {
            if ($item['resolution']['series'] instanceof RecurringSeries) {
                $protectedExistingIds[] = (string) $item['resolution']['series']->id;
            }
        }

        foreach ($existing->reject->trashed() as $series) {
            foreach ($candidates as $candidate) {
                if ($this->couldBelongToCandidate($series, $candidate)) {
                    $protectedExistingIds[] = (string) $series->id;
                    break;
                }
            }
        }

        return [
            'user' => $user,
            'space' => $space,
            'candidates' => $candidates,
            'plans' => $plans,
            'existing' => $existing,
            'protected_existing_ids' => array_values(array_unique($protectedExistingIds)),
        ];
    }

    /**
     * The transactions detection is allowed to look at. Future postings and
     * rows belonging to deleted or archived accounts are not observations for a
     * new projection; existing pivots remain untouched by apply.
     *
     * Each one is a movement as the account made it: a split charge is one
     * occurrence, its original, at the full amount and on its date, never its
     * parts on the same day (which no cadence tolerates).
     *
     * @return Builder<Transaction>
     */
    private function candidates(User $user, Space $space, CarbonImmutable $since): Builder
    {
        return Transaction::query()
            ->movements()
            ->where('user_id', $user->id)
            ->where('space_id', $space->id)
            ->whereNull('description_iv')
            ->transactionDateWithin($since->toDateString(), CarbonImmutable::today()->toDateString())
            ->whereHas('account', fn (Builder $query): Builder => $query->whereNull('archived_at'));
    }

    /**
     * Bucket transactions by stable merchant identity, account, direction and
     * currency. Account is evidence for separating two contracts at one
     * provider; observed field is deliberately not part of the bucket.
     *
     * @param  array<string, int>  $documentFrequency
     * @return array<string, array{match_field: string, merchant_key: string, stable_key: string, identity_aliases: list<string>, direction: string, currency_code: string, display_name: string, account_id: string, transactions: list<array{id: string, date: CarbonImmutable, amount: int, account_id: string}>}>
     */
    private function group(
        User $user,
        Space $space,
        CarbonImmutable $since,
        array $documentFrequency,
        float $noiseThreshold,
    ): array {
        $groups = [];
        // A counterparty field holding the holder's own name is not a
        // counterparty, and reading it as one files every such charge — a
        // social-security direct debit, a sweep into savings — under one key.
        // The spellings come from the user, because the name a bank prints is
        // rarely the one they signed up with.
        $ownerNames = $user->bankAliases();

        $this->candidates($user, $space, $since)
            ->select(['id', 'description', 'creditor_name', 'debtor_name', 'transaction_date', 'amount', 'currency_code', 'account_id'])
            ->lazyById()
            ->each(function (Transaction $transaction) use (&$groups, $documentFrequency, $noiseThreshold, $ownerNames): void {
                if ((int) $transaction->amount === 0 || ! filled($transaction->account_id)) {
                    return;
                }

                $key = $this->merchantKeys->keyFor($transaction, $documentFrequency, $noiseThreshold, $ownerNames);
                $stableKey = $this->merchantKeys->stableKeyFor($transaction, $documentFrequency, $noiseThreshold, $ownerNames);

                if ($key === null || $stableKey === null) {
                    return;
                }

                [$matchField] = $key;
                $direction = $transaction->amount < 0 ? 'expense' : 'income';
                $currency = (string) $transaction->currency_code;
                $accountId = (string) $transaction->account_id;
                $bucket = implode('|', [$stableKey, $direction, $currency, $accountId]);

                $groups[$bucket] ??= [
                    'match_field' => $matchField,
                    'merchant_key' => $stableKey,
                    'stable_key' => $stableKey,
                    'identity_aliases' => [],
                    'direction' => $direction,
                    'currency_code' => $currency,
                    'display_name' => $this->merchantKeys->displayNameFor($transaction, $ownerNames),
                    'account_id' => $accountId,
                    'transactions' => [],
                ];

                $groups[$bucket]['identity_aliases'] = array_values(array_unique(array_merge(
                    $groups[$bucket]['identity_aliases'],
                    $this->merchantKeys->aliasKeysFor($transaction, $documentFrequency, $noiseThreshold, $ownerNames),
                )));
                $groups[$bucket]['transactions'][] = [
                    'id' => $transaction->id,
                    'date' => CarbonImmutable::parse($transaction->transaction_date),
                    'amount' => (int) $transaction->amount,
                    'account_id' => $accountId,
                ];
            });

        return $groups;
    }

    /**
     * Keep groups that repeat and describe how.
     *
     * @param  array<string, array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function classify(array $groups): array
    {
        $minOccurrences = (int) config('recurring.min_occurrences');
        $priceWindow = max(1, (int) config('recurring.price_window'));
        $series = [];

        foreach ($groups as $group) {
            /** @var list<array{id: string, date: CarbonImmutable, amount: int, account_id: string}> $occurrences */
            $occurrences = $group['transactions'];

            if (count($occurrences) < $minOccurrences) {
                continue;
            }

            usort($occurrences, fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            $dates = array_map(fn (array $occurrence): CarbonImmutable => $occurrence['date'], $occurrences);
            $match = $this->classifier->classify($dates);

            if (! $match instanceof CadenceMatch) {
                continue;
            }

            $amounts = array_map(fn (array $occurrence): int => $occurrence['amount'], $occurrences);
            $firstOccurred = $dates[0];
            $lastOccurred = $dates[count($dates) - 1];
            $anchorDay = $this->anchorDay($match->cadence, $dates);

            $series[] = [
                'match_field' => $group['match_field'],
                'merchant_key' => $group['merchant_key'],
                'stable_key' => $group['stable_key'],
                'identity_aliases' => $group['identity_aliases'],
                'display_name' => $group['display_name'],
                'direction' => $group['direction'],
                'currency_code' => $group['currency_code'],
                'cadence' => $match->cadence,
                'interval_days' => $match->medianGapDays,
                'anchor_day' => $anchorDay,
                'expected_amount' => (int) round(Statistics::median($amounts)),
                'recent_amount' => (int) round(Statistics::recentMedian($amounts, $priceWindow)),
                'amount_is_variable' => $this->isVariable(array_slice($amounts, -$priceWindow)),
                'account_id' => $group['account_id'],
                'first_occurred_on' => $firstOccurred,
                'last_occurred_on' => $lastOccurred,
                'next_expected_on' => $match->cadence->advance($lastOccurred, $anchorDay),
                'occurrence_count' => count($occurrences),
                'transaction_ids' => array_map(fn (array $occurrence): string => $occurrence['id'], $occurrences),
            ];
        }

        return $series;
    }

    /**
     * Apply one pure plan atomically. Review-required candidates are not
     * mutated; unmatched active series lapse but keep their history and pivots.
     *
     * @param  array<string, mixed>  $plan
     */
    private function persist(array $plan): int
    {
        /** @var User $user */
        $user = $plan['user'];
        /** @var Space $space */
        $space = $plan['space'];

        return DB::transaction(function () use ($plan, $user, $space): int {
            /** @var EloquentCollection<int, RecurringSeries> $existing */
            $existing = RecurringSeries::withTrashed()
                ->where('user_id', $user->id)
                ->where('space_id', $space->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $rows = [];
            $appliedTransactionIds = [];
            $protectedIds = $plan['protected_existing_ids'];
            $written = 0;

            foreach ($plan['plans'] as $item) {
                $candidate = $item['candidate'];
                $resolution = $item['resolution'];
                $series = $resolution['series'];

                if ($resolution['action'] === 'review_required') {
                    continue;
                }

                if ($series instanceof RecurringSeries) {
                    $row = $existing->get($series->id);

                    if (! $row instanceof RecurringSeries || $row->trashed()) {
                        continue;
                    }

                    $protectedIds[] = (string) $row->id;
                    $aliases = $this->mergeAliases($row->identity_aliases, $candidate['identity_aliases']);
                    $absorbed = $this->absorbInto($row, $resolution['absorbed'] ?? [], $existing);
                    $aliases = $this->mergeAliases($aliases, $absorbed['aliases']);
                    $row->fill([
                        'user_state' => $absorbed['user_state'] ?? $row->user_state,
                        'identity_key' => $this->identities->identityKey($candidate),
                        'identity_aliases' => $aliases,
                        'cadence' => $candidate['cadence'],
                        'interval_days' => $candidate['interval_days'],
                        'anchor_day' => $candidate['anchor_day'],
                        'expected_amount' => $candidate['expected_amount'],
                        'recent_amount' => $candidate['recent_amount'],
                        'amount_is_variable' => $candidate['amount_is_variable'],
                        'account_id' => $candidate['account_id'],
                        'category_id' => $candidate['category_id'],
                        'first_occurred_on' => $candidate['first_occurred_on'],
                        'last_occurred_on' => $candidate['last_occurred_on'],
                        'next_expected_on' => $candidate['next_expected_on'],
                        'occurrence_count' => $candidate['occurrence_count'],
                        'status' => $this->resolveStatus(
                            $candidate['last_occurred_on'],
                            $candidate['interval_days'],
                            $row->user_state === RecurringSeriesUserState::Confirmed,
                        ),
                    ]);
                } else {
                    $row = new RecurringSeries([
                        'user_id' => $user->id,
                        'space_id' => $space->id,
                        'match_field' => $candidate['match_field'],
                        'merchant_key' => $candidate['merchant_key'],
                        'identity_key' => $this->identities->identityKey($candidate),
                        'identity_aliases' => $candidate['identity_aliases'],
                        'display_name' => $candidate['display_name'],
                        'user_state' => RecurringSeriesUserState::Detected,
                        'cadence' => $candidate['cadence'],
                        'interval_days' => $candidate['interval_days'],
                        'anchor_day' => $candidate['anchor_day'],
                        'expected_amount' => $candidate['expected_amount'],
                        'recent_amount' => $candidate['recent_amount'],
                        'amount_is_variable' => $candidate['amount_is_variable'],
                        'account_id' => $candidate['account_id'],
                        'category_id' => $candidate['category_id'],
                        'direction' => $candidate['direction'],
                        'currency_code' => $candidate['currency_code'],
                        'first_occurred_on' => $candidate['first_occurred_on'],
                        'last_occurred_on' => $candidate['last_occurred_on'],
                        'next_expected_on' => $candidate['next_expected_on'],
                        'occurrence_count' => $candidate['occurrence_count'],
                        'status' => $this->resolveStatus(
                            $candidate['last_occurred_on'],
                            $candidate['interval_days'],
                            false,
                        ),
                    ]);
                }

                $row->save();
                $rows[] = ['row' => $row, 'transaction_ids' => $candidate['transaction_ids']];
                $appliedTransactionIds = array_merge($appliedTransactionIds, $candidate['transaction_ids']);
                $written++;
            }

            $appliedTransactionIds = array_values(array_unique($appliedTransactionIds));
            if ($appliedTransactionIds !== []) {
                RecurringSeriesTransaction::query()
                    ->whereIn('transaction_id', $appliedTransactionIds)
                    ->delete();

                foreach ($rows as $item) {
                    $item['row']->transactions()->attach(array_values(array_unique($item['transaction_ids'])));
                }
            }

            $existing
                ->reject(fn (RecurringSeries $row, int|string $id): bool => $row->trashed()
                    || in_array((string) $id, array_values(array_unique($protectedIds)), true))
                ->each(function (RecurringSeries $row): void {
                    $row->update(['status' => RecurringSeriesStatus::Lapsed]);
                });

            return $written;
        });
    }

    /**
     * Report counts and signed expected-total deltas grouped by direction and
     * currency. It contains no model instances and is safe to serialize.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function previewReport(array $plan): array
    {
        $counts = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'merged' => 0,
            'review_required' => 0,
        ];
        $candidateTotals = [];
        $existingTotals = [];

        foreach ($plan['existing']->reject->trashed() as $series) {
            $key = $this->totalKey((string) $series->direction, (string) $series->currency_code);
            $existingTotals[$key] = ($existingTotals[$key] ?? 0) + (int) $series->expected_amount;
        }

        foreach ($plan['plans'] as $item) {
            $action = $item['resolution']['action'];
            $counts[$action]++;
            $candidate = $item['candidate'];
            $key = $this->totalKey($candidate['direction'], $candidate['currency_code']);
            $candidateTotals[$key] = ($candidateTotals[$key] ?? 0) + (int) $candidate['expected_amount'];
        }

        $delta = [];
        foreach (array_unique(array_merge(array_keys($existingTotals), array_keys($candidateTotals))) as $key) {
            $delta[$key] = ($candidateTotals[$key] ?? 0) - ($existingTotals[$key] ?? 0);
        }

        return [
            'user_id' => (string) $plan['user']->id,
            'space_id' => (string) $plan['space']->id,
            ...$counts,
            'delta' => $delta,
            'candidates' => collect($plan['plans'])->map(fn (array $item): array => [
                'action' => $item['resolution']['action'],
                'reason' => $item['resolution']['reason'],
                'stable_key' => $item['candidate']['stable_key'],
                'direction' => $item['candidate']['direction'],
                'currency_code' => $item['candidate']['currency_code'],
                'account_id' => $item['candidate']['account_id'],
                'transaction_count' => count($item['candidate']['transaction_ids']),
            ])->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     * @return array<string, mixed>
     */
    private function sumPreviewReports(array $reports): array
    {
        $totals = [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'merged' => 0,
            'review_required' => 0,
            'delta' => [],
        ];

        foreach ($reports as $report) {
            foreach (['created', 'updated', 'unchanged', 'merged', 'review_required'] as $key) {
                $totals[$key] += (int) $report[$key];
            }

            foreach ($report['delta'] as $key => $value) {
                $totals['delta'][$key] = ($totals['delta'][$key] ?? 0) + $value;
            }
        }

        return $totals;
    }

    private function totalKey(string $direction, string $currency): string
    {
        return "{$direction}:{$currency}";
    }

    /**
     * The category each series shows, resolved through effective postings.
     *
     * @param  list<array<string, mixed>>  $series
     * @return array<string, string|null>
     */
    private function effectiveCategories(array $series): array
    {
        $allIds = collect($series)->flatMap(fn (array $candidate): array => $candidate['transaction_ids'])->unique();

        if ($allIds->isEmpty()) {
            return [];
        }

        /** @var EloquentCollection<int, Transaction> $transactions */
        $transactions = Transaction::query()
            ->movements()
            ->whereIn('id', $allIds)
            ->with(['splits', 'splitParts:id,split_parent_id,category_id,amount'])
            ->get()
            ->keyBy('id');
        $categories = [];

        foreach ($series as $candidate) {
            $tally = [];

            foreach ($candidate['transaction_ids'] as $transactionId) {
                $transaction = $transactions->get($transactionId);
                $categoryId = $transaction instanceof Transaction ? $this->dominantCategoryId($transaction) : null;

                if ($categoryId !== null) {
                    $tally[$categoryId] = ($tally[$categoryId] ?? 0) + 1;
                }
            }

            arsort($tally);
            $categories[$this->candidateIdentity($candidate)] = array_key_first($tally);
        }

        return $categories;
    }

    /**
     * The category that carries most of one occurrence: the largest part of a
     * split, the largest line of one still split the old way, or its own.
     */
    private function dominantCategoryId(Transaction $transaction): ?string
    {
        if ($transaction->splitParts->isNotEmpty()) {
            return $transaction->splitParts
                ->sortByDesc(fn (Transaction $part): int => abs($part->amount))
                ->first()
                ?->category_id;
        }

        return $this->postings
            ->forTransaction($transaction)
            ->sortByDesc(fn ($posting): int => abs($posting->amount))
            ->first()
            ?->categoryId;
    }

    private function candidateIdentity(array $candidate): string
    {
        return implode('|', [
            $candidate['stable_key'],
            $candidate['direction'],
            $candidate['currency_code'],
            $candidate['account_id'],
        ]);
    }

    /**
     * Leave at most one candidate holding each stored series.
     *
     * One obligation can answer to two candidates at once: a contract billed to
     * a new account still matches the series through the old one, so the same
     * row is claimed twice — once as a plain update and once as an account
     * change. Applied in turn they overwrite each other, and whichever ran last
     * describes the series. When that is the account which stopped paying, a
     * live obligation is written back with a year-old date and reads as
     * cancelled, while the charges still arriving produce nothing at all.
     *
     * The still-charging candidate keeps the series, history and user's
     * decisions included. The others are left to stand on their own, which is
     * what they would have done had the series never existed: the account that
     * stopped gets a series that says so.
     *
     * @param  list<array{candidate: array<string, mixed>, resolution: array<string, mixed>}>  $plans
     * @return list<array{candidate: array<string, mixed>, resolution: array<string, mixed>}>
     */
    private function oneCandidatePerSeries(array $plans): array
    {
        $holderIndexes = [];

        foreach ($plans as $index => $item) {
            $series = $item['resolution']['series'];

            if (! $series instanceof RecurringSeries) {
                continue;
            }

            $held = $holderIndexes[$series->id] ?? null;

            if ($held === null || $this->chargesLaterThan($item, $plans[$held])) {
                $holderIndexes[$series->id] = $index;
            }
        }

        $holders = array_flip($holderIndexes);

        foreach ($plans as $index => $item) {
            if ($item['resolution']['series'] instanceof RecurringSeries && ! isset($holders[$index])) {
                $plans[$index]['resolution'] = [
                    'action' => 'created',
                    'series' => null,
                    'reason' => 'superseded_by_current_account',
                    'absorbed' => [],
                ];
            }
        }

        return $plans;
    }

    /**
     * @param  array{candidate: array<string, mixed>, resolution: array<string, mixed>}  $item
     * @param  array{candidate: array<string, mixed>, resolution: array<string, mixed>}  $against
     */
    private function chargesLaterThan(array $item, array $against): bool
    {
        return CarbonImmutable::parse($item['candidate']['last_occurred_on'])
            ->greaterThan(CarbonImmutable::parse($against['candidate']['last_occurred_on']));
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function couldBelongToCandidate(RecurringSeries $series, array $candidate): bool
    {
        if ($series->direction !== $candidate['direction'] || $series->currency_code !== $candidate['currency_code']) {
            return false;
        }

        return $this->identities->matchesIdentity($series, (string) $candidate['stable_key']);
    }

    /**
     * Collapse duplicate rows into the survivor.
     *
     * Their charges move across so the history survives, their aliases move so
     * the names they were known by keep matching, and their decisions move
     * because a user who confirmed one of these confirmed the obligation, not
     * the row. They are then marked as absorbed and soft deleted, which keeps
     * them off every screen while staying distinguishable from a series the
     * user deleted — that one detection must never bring back.
     *
     * @param  list<RecurringSeries>  $absorbed
     * @param  EloquentCollection<int, RecurringSeries>  $existing
     * @return array{aliases: list<string>, user_state: RecurringSeriesUserState|null}
     */
    private function absorbInto(RecurringSeries $survivor, array $absorbed, EloquentCollection $existing): array
    {
        $aliases = [];
        $userState = null;

        foreach ($absorbed as $planned) {
            $row = $existing->get($planned->id);

            if (! $row instanceof RecurringSeries || $row->trashed() || $row->is($survivor)) {
                continue;
            }

            $aliases = array_merge($aliases, $row->identityAliases(), array_filter([
                (string) $row->getAttribute('identity_key'),
                $this->merchantKeys->canonicalKey((string) $row->getAttribute('merchant_key')),
            ]));

            if ($row->user_state === RecurringSeriesUserState::Confirmed) {
                $userState = RecurringSeriesUserState::Confirmed;
            } elseif ($row->user_state === RecurringSeriesUserState::Ignored && $userState === null) {
                $userState = RecurringSeriesUserState::Ignored;
            }

            RecurringSeriesTransaction::query()
                ->where('recurring_series_id', $row->id)
                ->update(['recurring_series_id' => $survivor->id]);

            $row->forceFill(['merged_into_id' => $survivor->id])->save();
            $row->delete();
        }

        return [
            'aliases' => array_values(array_unique(array_filter($aliases))),
            'user_state' => $userState === RecurringSeriesUserState::Ignored
                && $survivor->user_state === RecurringSeriesUserState::Confirmed
                    ? RecurringSeriesUserState::Confirmed
                    : $userState,
        ];
    }

    private function mergeAliases(array|string|null $stored, array $observed): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return array_values(array_unique(array_filter(array_merge($stored ?? [], $observed))));
    }

    /**
     * Whether a series still looks current.
     */
    private function resolveStatus(CarbonImmutable $lastOccurred, int $intervalDays, bool $isConfirmed): RecurringSeriesStatus
    {
        $multiplier = (float) config($isConfirmed
            ? 'recurring.confirmed_lapse_interval_multiplier'
            : 'recurring.lapse_interval_multiplier');
        $cutoff = $lastOccurred->addDays((int) round($intervalDays * $multiplier));

        return CarbonImmutable::today()->greaterThan($cutoff)
            ? RecurringSeriesStatus::Lapsed
            : RecurringSeriesStatus::Active;
    }

    /**
     * The day of the month a charge is really billed on.
     *
     * The most common day across the occurrences, and the later day when two
     * are equally common. A contract billed on the 31st reads as the 28th every
     * February, and the clipped day must not win the vote — it is the one thing
     * the anchor exists to undo.
     *
     * @param  list<CarbonImmutable>  $dates
     */
    private function anchorDay(RecurringCadence $cadence, array $dates): ?int
    {
        if ($cadence->monthsPerOccurrence() === null) {
            return null;
        }

        $tally = [];

        foreach ($dates as $date) {
            $tally[$date->day] = ($tally[$date->day] ?? 0) + 1;
        }

        // Sorts are stable, so ordering by day first leaves the later day ahead
        // of an equally common earlier one.
        krsort($tally);
        arsort($tally);

        return (int) array_key_first($tally);
    }

    /** @param  list<int>  $amounts */
    private function isVariable(array $amounts): bool
    {
        $median = Statistics::median($amounts);

        if ((int) $median === 0) {
            return false;
        }

        return (Statistics::medianAbsoluteDeviation($amounts, $median) / abs($median))
            > (float) config('recurring.amount_variance_threshold');
    }
}
