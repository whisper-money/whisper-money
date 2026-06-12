<?php

namespace App\Jobs;

use App\Models\SuggestionRun;
use App\Services\Ai\GenerateRuleSuggestions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
}
