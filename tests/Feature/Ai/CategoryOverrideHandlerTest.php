<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\RuleOrigin;
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

it('learns a forward rule from an ai correction so the next merchant transaction skips ai', function () {
    $user = User::factory()->create();
    $wrong = cohCategory($user);
    $right = cohCategory($user);

    cohLearnRule($user, $wrong->id, 'Mercadona');
    $matched = cohMatched($user, 'Mercadona');

    $learned = app(CategoryOverrideHandler::class)->record($matched, $right->id);

    expect($learned)->not->toBeNull()
        ->and($learned->origin)->toBe(RuleOrigin::Correction)
        ->and($learned->action_category_id)->toBe($right->id)
        ->and($learned->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'mercadona']]);

    $next = cohMerchantTxn($user, 'Mercadona');
    app(AutomationRuleService::class)->applyRules($next);
    $next->refresh();

    expect($next->category_id)->toBe($right->id)
        ->and($next->category_source)->toBe(CategorySource::Rule)
        ->and($next->categorized_by_rule_id)->toBe($learned->id);
});

it('learns a description rule when there is no merchant key', function () {
    $user = User::factory()->create();
    $to = cohCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => cohCategory($user)->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.9,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'Netflix subscription',
    ]);

    $learned = app(CategoryOverrideHandler::class)->record($transaction, $to->id);

    expect($learned)->not->toBeNull()
        ->and($learned->origin)->toBe(RuleOrigin::Correction)
        ->and($learned->rules_json)->toBe([
            'and' => [
                ['in' => ['netflix', ['var' => 'description']]],
                ['in' => ['subscription', ['var' => 'description']]],
            ],
        ]);

    $next = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'NETFLIX SUBSCRIPTION 12,99',
    ]);
    app(AutomationRuleService::class)->applyRules($next);

    expect($next->refresh()->category_id)->toBe($to->id);
});

it('does not learn an over-broad description rule', function () {
    config()->set('ai_suggestions.overbroad_fraction', 0.1);

    $user = User::factory()->create();
    $to = cohCategory($user);

    foreach (range(1, 5) as $ignored) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'category_id' => null,
            'creditor_name' => null,
            'debtor_name' => null,
            'description' => 'Generic payment note',
        ]);
    }

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => cohCategory($user)->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.9,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'Generic payment note',
    ]);

    $learned = app(CategoryOverrideHandler::class)->record($transaction, $to->id);

    expect($learned)->toBeNull()
        ->and(AutomationRule::query()->origin(RuleOrigin::Correction)->count())->toBe(0);
});

it('moves a merchant key to the new category when the user changes their mind', function () {
    $user = User::factory()->create();
    $first = cohCategory($user);
    $second = cohCategory($user);

    cohLearnRule($user, cohCategory($user)->id, 'Mercadona');
    $matched = cohMatched($user, 'Mercadona');
    $firstRule = app(CategoryOverrideHandler::class)->record($matched, $first->id);

    $later = cohMerchantTxn($user, 'Mercadona');
    app(AutomationRuleService::class)->applyRules($later);
    $later->refresh();

    expect($later->categorized_by_rule_id)->toBe($firstRule->id);

    $secondRule = app(CategoryOverrideHandler::class)->record($later, $second->id);

    expect($secondRule->action_category_id)->toBe($second->id)
        ->and($secondRule->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'mercadona']])
        ->and(AutomationRule::query()->find($firstRule->id))->toBeNull()
        ->and(CategoryCorrection::query()->count())->toBe(1);
});

it('out-ranks an ai rule when a direct ai label is corrected for the same merchant', function () {
    $user = User::factory()->create();
    $wrong = cohCategory($user);
    $right = cohCategory($user);

    // An ai rule already maps mercadona -> wrong (learned from an earlier txn).
    $aiRule = cohLearnRule($user, $wrong->id, 'Mercadona');

    // A different mercadona transaction was labeled DIRECTLY by the model: it
    // carries no rule id, so the old self-heal would have left the ai rule intact.
    $direct = cohMerchantTxn($user, 'Mercadona');
    $direct->update([
        'category_id' => $wrong->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.8,
    ]);

    app(CategoryOverrideHandler::class)->record($direct, $right->id);

    $next = cohMerchantTxn($user, 'Mercadona');
    app(AutomationRuleService::class)->applyRules($next);
    $next->refresh();

    expect($next->category_id)->toBe($right->id)
        ->and(AutomationRule::query()->find($aiRule->id))->toBeNull();
});

it('self-heals but learns nothing when correcting to uncategorized', function () {
    $user = User::factory()->create();
    cohLearnRule($user, cohCategory($user)->id, 'Mercadona');
    $matched = cohMatched($user, 'Mercadona');
    $aiRuleId = $matched->categorized_by_rule_id;

    $learned = app(CategoryOverrideHandler::class)->record($matched, null);

    expect($learned)->toBeNull()
        ->and(AutomationRule::query()->find($aiRuleId))->toBeNull()
        ->and(AutomationRule::query()->origin(RuleOrigin::Correction)->count())->toBe(0);
});

it('learns a debtor_name rule when only the debtor is present', function () {
    $user = User::factory()->create();
    $to = cohCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => cohCategory($user)->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.9,
        'creditor_name' => null,
        'debtor_name' => 'Juan Perez',
        'description' => 'Bizum recibido',
    ]);

    $learned = app(CategoryOverrideHandler::class)->record($transaction, $to->id);

    expect($learned->rules_json)->toBe(['==' => [['var' => 'debtor_name'], 'juan perez']]);

    $next = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'creditor_name' => null,
        'debtor_name' => 'Juan Perez',
        'description' => 'Bizum recibido de nuevo',
    ]);
    app(AutomationRuleService::class)->applyRules($next);

    expect($next->refresh()->category_id)->toBe($to->id);
});

it('learns a single-token description rule as a bare contains clause', function () {
    $user = User::factory()->create();
    $to = cohCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => cohCategory($user)->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.9,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'Spotify',
    ]);

    $learned = app(CategoryOverrideHandler::class)->record($transaction, $to->id);

    expect($learned->rules_json)->toBe(['in' => ['spotify', ['var' => 'description']]]);
});

it('learns nothing from an encrypted-description transaction without a merchant', function () {
    $user = User::factory()->create();
    $to = cohCategory($user);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => cohCategory($user)->id,
        'category_source' => CategorySource::Ai,
        'ai_confidence' => 0.9,
        'creditor_name' => null,
        'debtor_name' => null,
    ]);

    expect($transaction->description_iv)->not->toBeNull();

    $learned = app(CategoryOverrideHandler::class)->record($transaction, $to->id);

    expect($learned)->toBeNull()
        ->and(AutomationRule::query()->origin(RuleOrigin::Correction)->count())->toBe(0);
});
