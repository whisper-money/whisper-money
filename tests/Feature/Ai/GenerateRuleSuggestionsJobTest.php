<?php

use App\Enums\SuggestionRunStatus;
use App\Jobs\GenerateRuleSuggestionsJob;
use App\Models\SuggestionRun;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Exceptions;

it('marks a stuck run as failed when the job dies outside the try/catch', function () {
    Exceptions::fake();

    $run = SuggestionRun::factory()->create(['status' => SuggestionRunStatus::Processing]);

    (new GenerateRuleSuggestionsJob($run))->failed(
        new TimeoutExceededException('timed out'),
    );

    expect($run->refresh()->status)->toBe(SuggestionRunStatus::Failed)
        ->and($run->error)->toBe('timed out');

    Exceptions::assertReported(TimeoutExceededException::class);
});

it('does not overwrite a run that already reached a terminal status', function () {
    Exceptions::fake();

    $run = SuggestionRun::factory()->create([
        'status' => SuggestionRunStatus::Completed,
        'suggestions_count' => 3,
    ]);

    (new GenerateRuleSuggestionsJob($run))->failed(new RuntimeException('late failure'));

    expect($run->refresh()->status)->toBe(SuggestionRunStatus::Completed)
        ->and($run->suggestions_count)->toBe(3);
});
