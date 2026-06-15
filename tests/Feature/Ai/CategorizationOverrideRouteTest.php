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
use App\Services\AutomationRuleService;

use function Pest\Laravel\actingAs;

function routeCategory(User $user): Category
{
    return Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

it('records provenance when an automation rule categorizes a transaction', function () {
    $user = User::factory()->create();
    $category = routeCategory($user);
    $rule = AutomationRule::factory()->for($user)->create([
        'action_category_id' => $category->id,
        'rules_json' => ['==' => [['var' => 'creditor_name'], 'mercadona']],
    ]);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'creditor_name' => 'mercadona',
    ]);

    app(AutomationRuleService::class)->applyRules($transaction);
    $transaction->refresh();

    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->category_source)->toBe(CategorySource::Rule)
        ->and($transaction->categorized_by_rule_id)->toBe($rule->id);
});

it('self-heals and logs a correction when the user overrides an ai category via the update route', function () {
    $user = User::factory()->create();
    $from = routeCategory($user);
    $to = routeCategory($user);

    // Learn an ai rule, then categorize a matching transaction through it.
    app(AiRuleLearner::class)->learn(new CategorizationOutcome(
        Transaction::factory()->plaintext()->create([
            'user_id' => $user->id, 'category_id' => null, 'creditor_name' => 'Mercadona', 'amount' => -1000,
        ]),
        $from->id, 0.95, true, true,
    ));

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id, 'category_id' => null, 'creditor_name' => 'Mercadona', 'amount' => -2000,
    ]);
    app(AutomationRuleService::class)->applyRules($transaction);
    $rule = $transaction->refresh()->categorized_by_rule_id;

    actingAs($user)
        ->patchJson(route('transactions.update', $transaction), ['category_id' => $to->id])
        ->assertOk();

    $transaction->refresh();

    expect($transaction->category_id)->toBe($to->id)
        ->and($transaction->category_source)->toBe(CategorySource::Manual)
        ->and($transaction->categorized_by_rule_id)->toBeNull()
        ->and($transaction->ai_confidence)->toBeNull()
        ->and(CategoryCorrection::query()->where('transaction_id', $transaction->id)->count())->toBe(1)
        ->and(AutomationRule::query()->find($rule))->toBeNull();
});
