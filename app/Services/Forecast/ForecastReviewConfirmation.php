<?php

namespace App\Services\Forecast;

use App\Enums\ForecastFrequency;
use App\Models\ForecastDismissal;
use App\Models\ForecastReviewCompletion;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies what the user answered in the forecast's review, in one go: the
 * yearly suggestions they added become lines, with the charges they stand
 * on paired to them; the ones they left out are dismissed so they are not
 * suggested again; and the yearly or one-off payments they typed in are
 * added. The first step only shows how each category is estimated, and its
 * edits are stored as they are made (see ForecastEstimateController), so
 * nothing of it comes here. Suggestions are read again here and named by
 * their key, so a suggestion the browser made up, or one that changed since
 * the review opened, is refused. The review is then marked answered in the
 * space, since it may have added no line (see ForecastReviewCompletion).
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
     * @param  array{annual?: list<string>, dismissed?: list<string>, manual?: list<array{name: string, amount: int, month: int, accountId: string, frequency: string}>}  $answers
     *
     * @throws ValidationException When an added suggestion is not on offer.
     */
    public function confirm(User $user, string $spaceId, array $answers): void
    {
        Cache::lock("forecast-review:{$user->id}", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, fn () => $this->apply($user, $spaceId, $answers));

        $this->count->forget($user, $spaceId);
    }

    /**
     * @param  array{annual?: list<string>, dismissed?: list<string>, manual?: list<array{name: string, amount: int, month: int, accountId: string, frequency: string}>}  $answers
     */
    private function apply(User $user, string $spaceId, array $answers): void
    {
        $byKey = $this->suggestionsByKey($user, $spaceId);
        $added = array_map(fn (string $key): ForecastCandidate => $byKey[$key] ?? throw ValidationException::withMessages([
            'annual' => __('Some suggestions changed since you opened the review. Open it again to see them.'),
        ]), $answers['annual'] ?? []);
        $dismissed = array_map(fn (string $key): ForecastCandidate => $byKey[$key], array_values(array_diff(
            array_intersect($answers['dismissed'] ?? [], array_keys($byKey)),
            $answers['annual'] ?? [],
        )));

        DB::transaction(function () use ($user, $spaceId, $added, $dismissed, $answers): void {
            foreach ($added as $candidate) {
                $this->writer->fromCandidates($user, $spaceId, [$candidate]);
            }

            foreach ($dismissed as $candidate) {
                ForecastDismissal::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'space_id' => $spaceId,
                    'fingerprint' => $candidate->identity(),
                    'kind' => $candidate->kind,
                ]);
            }

            foreach ($answers['manual'] ?? [] as $payment) {
                $this->writer->manual($user, $spaceId, $payment['accountId'], $payment['name'], $payment['amount'], $payment['month'], ForecastFrequency::from($payment['frequency']));
            }

            ForecastReviewCompletion::query()->updateOrCreate(
                ['user_id' => $user->id, 'space_id' => $spaceId],
                ['completed_at' => now()],
            );
        });
    }

    /**
     * @return array<string, ForecastCandidate>
     */
    private function suggestionsByKey(User $user, string $spaceId): array
    {
        $byKey = [];

        foreach ($this->suggestions->for($user, $spaceId) as $candidate) {
            $byKey[$candidate->reviewKey()] = $candidate;
        }

        return $byKey;
    }
}
