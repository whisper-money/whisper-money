<?php

namespace App\Services\Forecast;

use App\Enums\ForecastFrequency;
use App\Models\ForecastDismissal;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies what the user answered in the forecast's review, in one go: the
 * suggestions they kept become lines (renamed, or merged several into one),
 * the ones they left out are dismissed so they are not suggested again, and
 * the yearly or one-off payments they typed in are added. Suggestions are
 * read again here and named by their key, so a suggestion the browser made
 * up, or one that changed since the review opened, is refused.
 *
 * A line merged from several suggestions keeps the identity of the one that
 * gives its schedule (ForecastLineWriter::primaryOf()); the others are
 * dismissed, since the line covers them.
 *
 * Answers are applied one at a time per user: a second submit (a double
 * click, another tab) reads the suggestions after the first one stored its
 * lines, finds them answered, and is refused instead of storing them twice.
 */
class ForecastReviewConfirmation
{
    private const LOCK_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly ForecastSuggestions $suggestions,
        private readonly ForecastLineWriter $writer,
        private readonly ForecastSuggestionCount $count,
    ) {}

    /**
     * @param  array{lines?: list<array{keys: list<string>, name?: string|null, destinationAccountId?: string|null}>, dismissed?: list<string>, manual?: list<array{name: string, amount: int, month: int, accountId: string, frequency: string}>}  $answers
     *
     * @throws ValidationException When a line names a suggestion that is not on offer, or merges suggestions that cannot be one line.
     */
    public function confirm(User $user, string $spaceId, array $answers): void
    {
        Cache::lock("forecast-review:{$user->id}", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn () => $this->apply($user, $spaceId, $answers));

        $this->count->forget($user, $spaceId);
    }

    /**
     * @param  array{lines?: list<array{keys: list<string>, name?: string|null, destinationAccountId?: string|null}>, dismissed?: list<string>, manual?: list<array{name: string, amount: int, month: int, accountId: string, frequency: string}>}  $answers
     */
    private function apply(User $user, string $spaceId, array $answers): void
    {
        $byKey = $this->suggestionsByKey($user, $spaceId);
        $lines = array_map(fn (array $line): array => [...$line, 'candidates' => $this->candidatesOf($line['keys'], $byKey)], $answers['lines'] ?? []);
        $kept = array_merge(...array_map(fn (array $line): array => $line['keys'], $lines));
        $dismissedKeys = array_diff(array_intersect($answers['dismissed'] ?? [], array_keys($byKey)), $kept);
        $dismissed = array_values(array_map(fn (string $key): ForecastCandidate => $byKey[$key], $dismissedKeys));

        DB::transaction(function () use ($user, $spaceId, $lines, $dismissed, $answers): void {
            foreach ($lines as $line) {
                $primary = ForecastLineWriter::primaryOf($line['candidates']);
                $this->writer->fromCandidates($user, $spaceId, $line['candidates'], $line['name'] ?? null, $line['destinationAccountId'] ?? null);
                $this->dismiss($user, $spaceId, array_values(array_filter($line['candidates'], fn (ForecastCandidate $candidate): bool => $candidate !== $primary)));
            }

            $this->dismiss($user, $spaceId, $dismissed);

            foreach ($answers['manual'] ?? [] as $payment) {
                $this->writer->manual($user, $spaceId, $payment['accountId'], $payment['name'], $payment['amount'], $payment['month'], ForecastFrequency::from($payment['frequency']));
            }
        });
    }

    /**
     * @return array<string, ForecastCandidate>
     */
    private function suggestionsByKey(User $user, string $spaceId): array
    {
        $suggestions = $this->suggestions->for($user, $spaceId);
        $byKey = [];

        foreach ([...$suggestions['recurring'], ...$suggestions['annual']] as $candidate) {
            $byKey[$candidate->reviewKey()] = $candidate;
        }

        return $byKey;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, ForecastCandidate>  $byKey
     * @return non-empty-list<ForecastCandidate>
     */
    private function candidatesOf(array $keys, array $byKey): array
    {
        $candidates = array_values(array_filter(array_map(fn (string $key): ?ForecastCandidate => $byKey[$key] ?? null, $keys)));

        if ($candidates === [] || count($candidates) !== count($keys)) {
            throw ValidationException::withMessages(['lines' => __('Some suggestions changed since you opened the review. Open it again to see them.')]);
        }

        foreach (array_slice($candidates, 1) as $candidate) {
            if (! $candidates[0]->canMergeWith($candidate, ForecastLineWriter::DATE_WINDOW_DAYS)) {
                throw ValidationException::withMessages(['lines' => __('Only movements of the same account, direction and schedule can be combined.')]);
            }
        }

        return $candidates;
    }

    /**
     * @param  list<ForecastCandidate>  $candidates
     */
    private function dismiss(User $user, string $spaceId, array $candidates): void
    {
        foreach ($candidates as $candidate) {
            ForecastDismissal::query()->firstOrCreate([
                'user_id' => $user->id,
                'space_id' => $spaceId,
                'fingerprint' => $candidate->identity(),
                'kind' => $candidate->kind,
            ]);
        }
    }
}
