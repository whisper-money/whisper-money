<?php

namespace App\Services\Ai;

use App\Enums\SuggestionRunStatus;
use App\Models\SuggestionRun;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs the full suggestion pipeline for a pending run: aggregate → generate →
 * guard → persist. Sets the run's terminal status. Only a run that yields at
 * least one usable suggestion is marked Completed (and therefore counts against
 * the monthly throttle); empty results and failures stay re-runnable.
 */
class GenerateRuleSuggestions
{
    public function __construct(
        private readonly RuleSuggestionAggregator $aggregator,
        private readonly RuleSuggestionGenerator $generator,
        private readonly RuleSuggestionGuard $guard,
        private readonly RuleSuggestionConsolidator $consolidator,
    ) {}

    public function run(SuggestionRun $run): SuggestionRun
    {
        $run->forceFill(['status' => SuggestionRunStatus::Processing])->save();

        try {
            $groups = $this->aggregator->groupsFor($run->user);

            $run->transactions_considered = array_sum(array_column($groups, 'count'));

            if ($groups === []) {
                return $this->finishEmpty($run);
            }

            $categories = $this->aggregator->categoryOptions($run->user);
            $raw = $this->generator->generate($groups, $categories);
            $validated = $this->consolidator->consolidate(
                $this->guard->validate($run->user, $raw, $categories),
            );

            if ($validated === []) {
                return $this->finishEmpty($run);
            }

            foreach ($validated as $suggestion) {
                $run->suggestions()->create($suggestion);
            }

            $run->forceFill([
                'status' => SuggestionRunStatus::Completed,
                'suggestions_count' => count($validated),
            ])->save();
        } catch (Throwable $exception) {
            report($exception);

            $run->forceFill([
                'status' => SuggestionRunStatus::Failed,
                'error' => Str::limit($exception->getMessage(), 500),
            ])->save();
        }

        return $run;
    }

    private function finishEmpty(SuggestionRun $run): SuggestionRun
    {
        $run->forceFill([
            'status' => SuggestionRunStatus::Empty,
            'suggestions_count' => 0,
        ])->save();

        return $run;
    }
}
