<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\CategoryCorrection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiRuleLearner;
use App\Services\Ai\CategorizationOutcome;
use App\Services\Ai\CategoryOverrideHandler;
use App\Services\AutomationRuleService;

function cohCategory(User $user): Category
{
    return Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

function cohMerchantTxn(User $user, string $creditor): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => $creditor,
        'description' => "{$creditor} compra",
    ]);
}

function cohLearnRule(User $user, string $categoryId, string $creditor): AutomationRule
{
    return app(AiRuleLearner::class)->learn(
        new CategorizationOutcome(cohMerchantTxn($user, $creditor), $categoryId, 0.95, true, true),
    );
}

function cohMatched(User $user, string $creditor): Transaction
{
    $transaction = cohMerchantTxn($user, $creditor);
    app(AutomationRuleService::class)->applyRules($transaction);

    return $transaction->refresh();
}

it('logs a correction and deletes the ai rule when its only merchant is corrected', function () {
    $user = User::factory()->create();
    $from = cohCategory($user);
    $to = cohCategory($user);

    $rule = cohLearnRule($user, $from->id, 'Mercadona');
    $matched = cohMatched($user, 'Mercadona');

    expect($matched->category_source)->toBe(CategorySource::Rule)
        ->and($matched->categorized_by_rule_id)->toBe($rule->id);

    app(CategoryOverrideHandler::class)->record($matched, $to->id);

    $correction = CategoryCorrection::query()->firstOrFail();

    expect($correction->from_category_id)->toBe($from->id)
        ->and($correction->to_category_id)->toBe($to->id)
        ->and($correction->source)->toBe(CategorySource::Rule)
        ->and(AutomationRule::query()->find($rule->id))->toBeNull();
});

it('drops only the corrected merchant from a multi-merchant ai rule', function () {
    $user = User::factory()->create();
    $from = cohCategory($user);
    $to = cohCategory($user);

    cohLearnRule($user, $from->id, 'Mercadona');
    $rule = cohLearnRule($user, $from->id, 'Carrefour');
    $matched = cohMatched($user, 'Mercadona');

    app(CategoryOverrideHandler::class)->record($matched, $to->id);

    expect($rule->refresh()->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'carrefour']])
        ->and(CategoryCorrection::query()->count())->toBe(1);
});

it('logs a correction for a direct ai label without any rule', function () {
    $user = User::factory()->create();
    $from = cohCategory($user);
    $to = cohCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $from->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.91,
    ]);

    app(CategoryOverrideHandler::class)->record($transaction, $to->id);

    $correction = CategoryCorrection::query()->firstOrFail();

    expect($correction->source)->toBe(CategorySource::Ai)
        ->and($correction->confidence)->toEqual(0.91);
});

it('ignores corrections to a user-owned rule', function () {
    $user = User::factory()->create();
    $from = cohCategory($user);
    $to = cohCategory($user);

    $rule = AutomationRule::factory()->for($user)->create(['action_category_id' => $from->id]);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $from->id,
        'category_source' => CategorySource::Rule,
        'categorized_by_rule_id' => $rule->id,
    ]);

    app(CategoryOverrideHandler::class)->record($transaction, $to->id);

    expect(CategoryCorrection::query()->count())->toBe(0)
        ->and(AutomationRule::query()->find($rule->id))->not->toBeNull();
});

it('ignores corrections to a manual category', function () {
    $user = User::factory()->create();
    $from = cohCategory($user);
    $to = cohCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $from->id,
        'category_source' => CategorySource::Manual,
    ]);

    app(CategoryOverrideHandler::class)->record($transaction, $to->id);

    expect(CategoryCorrection::query()->count())->toBe(0);
});

it('does nothing when the category is unchanged', function () {
    $user = User::factory()->create();
    $category = cohCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.9,
    ]);

    app(CategoryOverrideHandler::class)->record($transaction, $category->id);

    expect(CategoryCorrection::query()->count())->toBe(0);
});
