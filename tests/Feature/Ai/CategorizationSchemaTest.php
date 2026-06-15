<?php

use App\Enums\CategorySource;
use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\CategoryCorrection;
use App\Models\Transaction;
use App\Models\User;

it('casts the AI categorization fields on a transaction', function () {
    $rule = AutomationRule::factory()->ai()->for(User::factory())->create();

    $transaction = Transaction::factory()->plaintext()->create([
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.873,
        'categorized_by_rule_id' => $rule->id,
    ]);

    $transaction->refresh();

    expect($transaction->category_source)->toBe(CategorySource::Ai)
        ->and($transaction->ai_confidence)->toBeFloat()->toEqual(0.873)
        ->and($transaction->categorizedByRule->is($rule))->toBeTrue();
});

it('hides the categorizing rule id from serialization but exposes the source', function () {
    $transaction = Transaction::factory()->plaintext()->create([
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

it('records a category correction with casted fields', function () {
    $correction = CategoryCorrection::factory()->create([
        'source' => CategorySource::Ai,
        'confidence' => 0.612,
    ]);

    $correction->refresh();

    expect($correction->source)->toBe(CategorySource::Ai)
        ->and($correction->confidence)->toBeFloat()->toEqual(0.612)
        ->and($correction->transaction)->not->toBeNull();
});
