<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiRuleLearner;
use App\Services\Ai\CategorizationOutcome;
use App\Services\AutomationRuleService;
use Illuminate\Support\Facades\DB;

function expenseCategory(User $user): Category
{
    return Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

function merchantTransaction(User $user, string $creditor): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => $creditor,
        'description' => "{$creditor} compra",
    ]);
}

function outcome(Transaction $transaction, string $categoryId, float $confidence = 0.95, bool $unambiguous = true): CategorizationOutcome
{
    return new CategorizationOutcome($transaction, $categoryId, $confidence, $unambiguous, true);
}

it('creates an ai-owned rule at the lowest priority and links the transaction', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);
    AutomationRule::factory()->for($user)->create(['priority' => 5]);
    $transaction = merchantTransaction($user, 'Mercadona');

    $rule = app(AiRuleLearner::class)->learn(outcome($transaction, $category->id));

    expect($rule)->not->toBeNull()
        ->and($rule->origin)->toBe(RuleOrigin::Ai)
        ->and($rule->action_category_id)->toBe($category->id)
        ->and($rule->priority)->toBe(6)
        ->and($rule->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'mercadona']])
        ->and($transaction->refresh()->categorized_by_rule_id)->toBe($rule->id);
});

it('resolves a fresh instance per container lookup so the memoized corpus cannot leak or go stale', function () {
    // The per-user corpus cache has no invalidation and is safe only while the
    // learner is never a singleton. Guard that invariant.
    expect(app(AiRuleLearner::class))->not->toBe(app(AiRuleLearner::class));
});

it('learns each correction correctly across a batch while loading the corpus once', function () {
    $user = User::factory()->create();
    $target = expenseCategory($user);
    // A separate category keeps the corrected txns out of the "uncategorized"
    // count, so the overbroad guard (which needs uncategorized rows) is a no-op
    // and each correction actually learns a description rule.
    $existing = expenseCategory($user);

    // Merchant-less, plaintext transactions force the description-token path,
    // which is what loads the per-user description corpus.
    $makeTxn = fn (string $description): Transaction => Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $existing->id,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => $description,
    ]);

    $first = $makeTxn('Netflix subscription');
    $second = $makeTxn('Spotify premium');

    // One instance across the batch, mirroring the bulkUpdate loop where the
    // handler (and its learner) is resolved once.
    $learner = app(AiRuleLearner::class);

    DB::enableQueryLog();
    $firstRule = $learner->learnFromCorrection($first, $target->id);
    $secondRule = $learner->learnFromCorrection($second, $target->id);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    // The second learning ran against the memoized corpus and still produced a
    // distinct, valid clause: both corrections live in the one target rule.
    expect($firstRule)->not->toBeNull()
        ->and($secondRule)->not->toBeNull()
        ->and($secondRule->id)->toBe($firstRule->id)
        ->and($secondRule->refresh()->rules_json)->toHaveKey('or')
        ->and($secondRule->rules_json['or'])->toHaveCount(2);

    // The corpus is the pluck of the `description` column (not the matcher's
    // count(*) probes, which also filter on description_iv), loaded once.
    $corpusLoads = $queries->filter(fn (array $q): bool => str_starts_with(strtolower(ltrim($q['query'])), 'select')
        && str_contains($q['query'], 'description_iv')
        && ! str_contains(strtolower($q['query']), 'count(')
    );

    expect($corpusLoads)->toHaveCount(1);
});

it('appends a new merchant to the existing ai rule for the same category', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    $first = app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Mercadona'), $category->id));
    $second = app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Carrefour'), $category->id));

    expect($second->id)->toBe($first->id)
        ->and(AutomationRule::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($second->refresh()->rules_json)->toBe([
            'or' => [
                ['==' => [['var' => 'creditor_name'], 'mercadona']],
                ['==' => [['var' => 'creditor_name'], 'carrefour']],
            ],
        ]);
});

it('does not duplicate a merchant already on the rule', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Mercadona'), $category->id));
    $rule = app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'mercadona'), $category->id));

    expect($rule->refresh()->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'mercadona']]);
});

it('learns a rule that categorizes a future transaction from the same merchant', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Mercadona'), $category->id));

    $future = merchantTransaction($user, 'Mercadona');
    app(AutomationRuleService::class)->applyRules($future);

    expect($future->refresh()->category_id)->toBe($category->id);
});

it('does not learn an ambiguous merchant', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    $rule = app(AiRuleLearner::class)->learn(
        outcome(merchantTransaction($user, 'Amazon'), $category->id, unambiguous: false),
    );

    expect($rule)->toBeNull()
        ->and(AutomationRule::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('does not learn below the rule confidence bar', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    $rule = app(AiRuleLearner::class)->learn(
        outcome(merchantTransaction($user, 'Mercadona'), $category->id, confidence: 0.8),
    );

    expect($rule)->toBeNull();
});

it('does not learn when the transaction has no merchant key', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -1000,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'card payment 1234',
    ]);

    expect(app(AiRuleLearner::class)->learn(outcome($transaction, $category->id)))->toBeNull();
});

it('never reuses a user-owned rule, even for the same category', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);
    $userRule = AutomationRule::factory()->for($user)->create(['action_category_id' => $category->id]);

    $aiRule = app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Mercadona'), $category->id));

    expect($aiRule->id)->not->toBe($userRule->id)
        ->and($aiRule->origin)->toBe(RuleOrigin::Ai);
});
