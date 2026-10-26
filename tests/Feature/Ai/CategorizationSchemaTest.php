<?php

use App\Enums\CategorySource;
use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\Transaction;
use App\Models\User;

it('hides the categorizing rule id from serialization but exposes the source', function () {
    $transaction = Transaction::factory()->create([
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.5,
    ]);

    $array = $transaction->toArray();

    expect($array)->toHaveKey('category_source')
        ->and($array)->toHaveKey('ai_confidence')
        ->and($array)->not->toHaveKey('categorized_by_rule_id');
});

it('defaults rule origin to user and supports the ai state and scope', function () {
    $user = User::factory()->create();

    $userRule = AutomationRule::factory()->for($user)->create();
    $aiRule = AutomationRule::factory()->ai()->for($user)->create();

    expect($userRule->refresh()->origin)->toBe(RuleOrigin::User)
        ->and($aiRule->refresh()->origin)->toBe(RuleOrigin::Ai);

    $aiRules = AutomationRule::query()->origin(RuleOrigin::Ai)->get();

    expect($aiRules)->toHaveCount(1)
        ->and($aiRules->first()->is($aiRule))->toBeTrue();
});

it('reports ai_categorized only for a direct AI label', function (CategorySource $source, bool $aiCategorized) {
    $transaction = Transaction::factory()->create([
        'category_source' => $source,
    ]);

    expect($transaction->ai_categorized)->toBe($aiCategorized);
})->with([
    'direct AI label' => [CategorySource::Ai, true],
    'manual category' => [CategorySource::Manual, false],
]);

it('reports ai_categorized only when the categorizing rule is AI-origin', function (bool $aiOrigin) {
    $user = User::factory()->create();
    $rule = $aiOrigin
        ? AutomationRule::factory()->ai()->for($user)->create()
        : AutomationRule::factory()->for($user)->create();

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_source' => CategorySource::Rule,
        'categorized_by_rule_id' => $rule->id,
    ]);
    $transaction->load('categorizedByRule');

    expect($transaction->ai_categorized)->toBe($aiOrigin);
})->with([
    'AI-origin rule' => true,
    'user-owned rule' => false,
]);
