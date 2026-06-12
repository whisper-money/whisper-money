<?php

use App\Enums\RuleSuggestionStatus;
use App\Enums\SuggestionRunStatus;
use App\Models\RuleSuggestion;
use App\Models\SuggestionRun;
use App\Models\User;

it('persists a run with suggestions and casts fields', function () {
    $user = User::factory()->create();

    $run = SuggestionRun::factory()->for($user)->create([
        'suggestions_count' => 2,
    ]);

    RuleSuggestion::factory()->count(2)->for($run, 'run')->create();

    $run->refresh()->loadCount('suggestions');

    expect($run->status)->toBe(SuggestionRunStatus::Completed)
        ->and($run->status->countsTowardThrottle())->toBeTrue()
        ->and($run->suggestions_count)->toBe(2)
        ->and($run->suggestions)->toHaveCount(2)
        ->and($run->suggestions->first()->status)->toBe(RuleSuggestionStatus::Pending)
        ->and($run->suggestions->first()->confidence)->toBeFloat()
        ->and($run->suggestions->first()->sample_descriptions)->toBeArray();
});

it('flags a suggestion that proposes a new category', function () {
    $suggestion = RuleSuggestion::factory()->proposesNewCategory('Pet care')->create();

    expect($suggestion->proposesNewCategory())->toBeTrue()
        ->and($suggestion->new_category_name)->toBe('Pet care');
});

it('does not count empty or failed runs toward the throttle', function () {
    expect(SuggestionRunStatus::Empty->countsTowardThrottle())->toBeFalse()
        ->and(SuggestionRunStatus::Failed->countsTowardThrottle())->toBeFalse()
        ->and(SuggestionRunStatus::Pending->countsTowardThrottle())->toBeFalse();
});
