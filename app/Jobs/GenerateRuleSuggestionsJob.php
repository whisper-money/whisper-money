<?php

namespace App\Jobs;

use App\Enums\SuggestionRunStatus;
use App\Models\SuggestionRun;
use App\Services\Ai\GenerateRuleSuggestions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GenerateRuleSuggestionsJob implements ShouldQueue
{
    use Queueable;

    /**
     * The generation can call a slow external model, so give it room.
     */
    public int $timeout = 120;

    public function __construct(public SuggestionRun $run) {}

    public function handle(GenerateRuleSuggestions $generator): void
    {
        $this->run->loadMissing('user');

        $generator->run($this->run);
    }

    /**
     * A job timeout or fatal worker crash escapes the try/catch inside
     * {@see GenerateRuleSuggestions::run()}, so mark the run as failed here too —
     * otherwise it stays "processing" forever and the onboarding client spins
     * indefinitely. Report the cause so we actually hear about these.
     */
    public function failed(?Throwable $exception): void
    {
        report($exception ?? new RuntimeException('GenerateRuleSuggestionsJob failed without an exception.'));

        $run = $this->run->fresh();

        if ($run === null || $run->status->isFinished()) {
            return;
        }

        $run->forceFill([
            'status' => SuggestionRunStatus::Failed,
            'error' => Str::limit($exception?->getMessage() ?? 'Job failed (timeout or worker crash).', 500),
        ])->save();
    }
}
