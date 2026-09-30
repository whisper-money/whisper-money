<?php

namespace App\Services\Ai\Evaluation;

use App\Enums\CategorySource;
use App\Exceptions\Ai\TransientCategorizationException;
use App\Models\AiConsent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;
use App\Services\Ai\Contracts\CategorizationBackend;
use App\Services\Ai\JevCategorizationBackend;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Laravel\Ai\Events\AgentPrompted;
use stdClass;
use Throwable;

/**
 * Offline, head-to-head evaluation of the categorization backends. The ground
 * truth is what users categorized by hand; every backend gets the same sample,
 * and nothing is written back, so it is safe to run against a copy of prod.
 *
 * Token usage is read off the events each backend's client fires, which keeps
 * the backends unaware that they are being measured.
 */
class CategorizationEvaluator
{
    private const int MAX_ATTEMPTS = 3;

    private ?BackendRun $measuring = null;

    private bool $listening = false;

    public function __construct(private readonly Dispatcher $events) {}

    /**
     * A random sample of hand-categorized transactions from users who still
     * have an account and an active AI consent: one per distinct description,
     * so a merchant paid every week counts once, and at most $perUser per
     * user, so a single heavy user cannot dominate the result.
     *
     * @return Collection<int, EvaluationGroup>
     */
    public function sample(int $size, int $perUser, int $seed, ?string $userId = null): Collection
    {
        $candidates = Transaction::query()
            ->selectRaw('MIN(id) as id, user_id')
            ->where('category_source', CategorySource::Manual)
            ->whereNotNull('category_id')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->whereIn('user_id', AiConsent::query()->active()->whereHas('user')->select('user_id'))
            ->when($userId !== null, fn (Builder $query): Builder => $query->where('user_id', $userId))
            ->groupBy('user_id', 'description')
            ->inRandomOrder((string) $seed)
            ->toBase()
            ->get();

        return Transaction::query()
            ->whereIn('id', $this->capPerUser($candidates, $size, $perUser))
            ->get()
            ->groupBy('user_id')
            ->map(fn (Collection $transactions, string $userId): EvaluationGroup => $this->group($userId, $transactions))
            ->filter(fn (EvaluationGroup $group): bool => $group->transactions->isNotEmpty())
            ->values();
    }

    /**
     * Send the sample to the backend in the same chunks production uses.
     *
     * @param  Collection<int, EvaluationGroup>  $groups
     * @param  (callable(int): void)|null  $onChunk  called with each chunk's size
     */
    public function run(string $name, CategorizationBackend $backend, Collection $groups, ?callable $onChunk = null): BackendRun
    {
        $this->listenForUsage();

        $run = $this->measuring = new BackendRun($name, $backend->model());
        $batchSize = max(1, (int) config('ai_categorization.group_batch_size'));

        try {
            foreach ($groups as $group) {
                foreach ($group->transactions->chunk($batchSize) as $chunk) {
                    $this->runChunk($run, $backend, $group->catalog, $chunk->values());

                    if ($onChunk !== null) {
                        $onChunk($chunk->count());
                    }
                }
            }
        } finally {
            $this->measuring = null;
        }

        return $run;
    }

    /**
     * Retry the transactions a failed call left unanswered, with backoff, so a
     * rate limit measures as retries and latency rather than as wrong answers.
     *
     * @param  Collection<int, Transaction>  $pending
     */
    private function runChunk(BackendRun $run, CategorizationBackend $backend, CategoryCatalog $catalog, Collection $pending): void
    {
        for ($attempt = 1; ; $attempt++) {
            $started = hrtime(true);
            [$results, $error] = $this->attempt($backend, $pending, $catalog);
            $run->callSeconds[] = (hrtime(true) - $started) / 1e9;

            $pending = $this->record($run, $catalog, $pending, $results);

            if ($error === null || $pending->isEmpty()) {
                break;
            }

            $run->errors[] = $error;

            if ($attempt === self::MAX_ATTEMPTS) {
                array_push($run->failed, ...$pending->pluck('id')->all());

                return;
            }

            $run->retries++;
            Sleep::for(2 ** $attempt)->seconds();
        }

        // A successful call that skipped a transaction found no category for it.
        foreach ($pending as $transaction) {
            $run->predictions[$transaction->id] = ['category_id' => null, 'confidence' => 0.0, 'merchant_unambiguous' => false];
        }
    }

    /**
     * @param  Collection<int, Transaction>  $pending
     * @return array{0: list<array<string, mixed>>, 1: ?string} the results and, when the call failed, why
     */
    private function attempt(CategorizationBackend $backend, Collection $pending, CategoryCatalog $catalog): array
    {
        try {
            return [$backend->categorize($pending, $catalog), null];
        } catch (TransientCategorizationException $exception) {
            return [$exception->results, $exception->getMessage()];
        } catch (Throwable $exception) {
            return [[], $exception::class.': '.$exception->getMessage()];
        }
    }

    /**
     * Store the results for transactions still pending and return the ones
     * that got no answer.
     *
     * @param  Collection<int, Transaction>  $pending
     * @param  list<array<string, mixed>>  $results
     * @return Collection<int, Transaction>
     */
    private function record(BackendRun $run, CategoryCatalog $catalog, Collection $pending, array $results): Collection
    {
        $pendingIds = $pending->pluck('id')->flip();

        foreach ($results as $result) {
            $ref = (string) ($result['ref'] ?? '');

            if (! $pendingIds->has($ref)) {
                continue;
            }

            $run->predictions[$ref] = [
                'category_id' => $catalog->categoryIdForIndex(isset($result['category_index']) ? (int) $result['category_index'] : null),
                'confidence' => (float) ($result['confidence'] ?? 0.0),
                'merchant_unambiguous' => (bool) ($result['merchant_unambiguous'] ?? false),
            ];
        }

        return $pending->reject(fn (Transaction $transaction): bool => isset($run->predictions[$transaction->id]))->values();
    }

    /**
     * Pick candidates in their random order, skipping users already at the cap.
     *
     * @param  Collection<int, stdClass>  $candidates
     * @return list<string>
     */
    private function capPerUser(Collection $candidates, int $size, int $perUser): array
    {
        $taken = [];
        $ids = [];

        foreach ($candidates as $candidate) {
            if (count($ids) >= $size) {
                break;
            }

            if (($taken[$candidate->user_id] ?? 0) < $perUser) {
                $taken[$candidate->user_id] = ($taken[$candidate->user_id] ?? 0) + 1;
                $ids[] = $candidate->id;
            }
        }

        return $ids;
    }

    /**
     * Only transactions whose category is still one of the user's leaves can
     * be answered correctly, so the rest are left out of the sample.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function group(string $userId, Collection $transactions): EvaluationGroup
    {
        $user = User::query()->findOrFail($userId);
        $catalog = CategoryCatalog::forUser($user);

        return new EvaluationGroup(
            $user,
            $catalog,
            $transactions->filter(fn (Transaction $transaction): bool => $catalog->pathForCategoryId($transaction->category_id) !== null)->values(),
        );
    }

    /**
     * Gemini's tokens come from the agent's response, Jev's from each HTTP
     * response body. Gemini bills thinking tokens as output.
     */
    private function listenForUsage(): void
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        $this->events->listen(AgentPrompted::class, function (AgentPrompted $event): void {
            $usage = $event->response->usage;

            $this->measuring?->addUsage(
                $usage->promptTokens + $usage->cacheReadInputTokens + $usage->cacheWriteInputTokens,
                $usage->completionTokens + $usage->reasoningTokens,
            );
        });

        $this->events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if ($event->request->url() !== JevCategorizationBackend::ENDPOINT) {
                return;
            }

            $this->measuring?->addUsage(
                (int) $event->response->json('usage.input_tokens', 0),
                (int) $event->response->json('usage.output_tokens', 0),
            );
        });
    }
}
