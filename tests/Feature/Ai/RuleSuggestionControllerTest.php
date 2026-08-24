<?php

use App\Enums\SuggestionRunStatus;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\RuleSuggestion;
use App\Models\SuggestionRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;

beforeEach(function () {
    config()->set('ai_suggestions.eligibility_min_transactions', 50);
    config()->set('ai_suggestions.confidence_floor', 0.7);
    config()->set('ai_suggestions.overbroad_fraction', 0.4);
    config()->set('ai_suggestions.min_match_count', 1);

    $this->user = User::factory()->notOnboarded()->create();
    $this->account = Account::factory()->for($this->user)->create();
});

function seedTransactions(User $user, Account $account, int $mercadona = 6, int $filler = 44): void
{
    for ($i = 0; $i < $mercadona; $i++) {
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'category_id' => null, 'description_iv' => null,
            'creditor_name' => 'MERCADONA', 'description' => "MERCADONA {$i}", 'amount' => -4000,
        ]);
    }
    for ($i = 0; $i < $filler; $i++) {
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'category_id' => null, 'description_iv' => null,
            'creditor_name' => null, 'description' => "UNIQUE MERCHANT {$i}", 'amount' => -1000,
        ]);
    }
}

function fakeGeneratorReturning(string $categoryId): void
{
    app()->instance(RuleSuggestionGenerator::class, new class($categoryId) implements RuleSuggestionGenerator
    {
        public function __construct(private string $categoryId) {}

        public function generate(array $groups, array $categoryOptions): array
        {
            return [[
                'group_key' => 'mercadona',
                'match_field' => 'creditor_name',
                'match_operator' => 'equals',
                'match_token' => 'mercadona',
                'category_id' => $this->categoryId,
                'confidence' => 0.95,
            ]];
        }
    });
}

it('blocks generation without consent', function () {
    $this->actingAs($this->user)
        ->postJson(route('ai.rule-suggestions.generate'))
        ->assertForbidden();
});

it('reports ineligible users with too few transactions', function () {
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account, mercadona: 3, filler: 5); // 8 total

    $this->actingAs($this->user)
        ->postJson(route('ai.rule-suggestions.generate'))
        ->assertStatus(422)
        ->assertJson(['eligible' => false, 'transaction_count' => 8]);
});

it('generates, persists and returns suggestions for an eligible user', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account);
    fakeGeneratorReturning($groceries->id);

    $response = $this->actingAs($this->user)
        ->postJson(route('ai.rule-suggestions.generate'))
        ->assertOk()
        ->assertJsonPath('run.status', SuggestionRunStatus::Completed->value)
        ->assertJsonPath('run.suggestions_count', 1)
        ->assertJsonPath('suggestions.0.values.0.match_token', 'mercadona');

    expect($response->json('suggestions.0.proposed_category.id'))->toBe($groceries->id);
});

it('groups pending suggestions that share a category into one card', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account); // 6 MERCADONA + 44 UNIQUE MERCHANT

    $run = SuggestionRun::factory()->for($this->user)->create(['status' => SuggestionRunStatus::Completed]);
    RuleSuggestion::factory()->for($run, 'run')->create([
        'match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'mercadona',
        'proposed_category_id' => $groceries->id, 'group_size' => 6,
    ]);
    RuleSuggestion::factory()->for($run, 'run')->create([
        'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'unique merchant',
        'proposed_category_id' => $groceries->id, 'group_size' => 44,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('ai.rule-suggestions.show'))
        ->assertOk();

    expect($response->json('suggestions'))->toHaveCount(1)
        ->and($response->json('suggestions.0.values'))->toHaveCount(2)
        ->and($response->json('suggestions.0.group_size'))->toBe(50)
        ->and($response->json('suggestions.0.proposed_category.id'))->toBe($groceries->id);
});

it('hides suggestions matching fewer transactions than the configured minimum', function () {
    config()->set('ai_suggestions.min_match_count', 10);

    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $shopping = Category::factory()->for($this->user)->create(['name' => 'Shopping', 'type' => 'expense']);
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account); // 6 MERCADONA + 44 UNIQUE MERCHANT

    $run = SuggestionRun::factory()->for($this->user)->create(['status' => SuggestionRunStatus::Completed]);
    RuleSuggestion::factory()->for($run, 'run')->create([
        'match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'mercadona',
        'proposed_category_id' => $groceries->id, 'group_size' => 6,
    ]);
    RuleSuggestion::factory()->for($run, 'run')->create([
        'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'unique merchant',
        'proposed_category_id' => $shopping->id, 'group_size' => 44,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('ai.rule-suggestions.show'))
        ->assertOk();

    // The MERCADONA card matches only 6 transactions (< 10) and is dropped.
    expect($response->json('suggestions'))->toHaveCount(1)
        ->and($response->json('suggestions.0.group_size'))->toBe(44)
        ->and($response->json('suggestions.0.proposed_category.id'))->toBe($shopping->id);
});

it('shows every suggestion when the minimum match count is one', function () {
    config()->set('ai_suggestions.min_match_count', 1);

    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $shopping = Category::factory()->for($this->user)->create(['name' => 'Shopping', 'type' => 'expense']);
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account);

    $run = SuggestionRun::factory()->for($this->user)->create(['status' => SuggestionRunStatus::Completed]);
    RuleSuggestion::factory()->for($run, 'run')->create([
        'match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'mercadona',
        'proposed_category_id' => $groceries->id, 'group_size' => 6,
    ]);
    RuleSuggestion::factory()->for($run, 'run')->create([
        'match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'unique merchant',
        'proposed_category_id' => $shopping->id, 'group_size' => 44,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('ai.rule-suggestions.show'))
        ->assertOk();

    expect($response->json('suggestions'))->toHaveCount(2);
});

it('reuses the latest run while throttled instead of generating again', function () {
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account);
    SuggestionRun::factory()->for($this->user)->create(['status' => SuggestionRunStatus::Completed]);

    $this->actingAs($this->user)
        ->postJson(route('ai.rule-suggestions.generate'))
        ->assertOk()
        ->assertJson(['throttled' => true]);

    expect($this->user->suggestionRuns()->count())->toBe(1);
});

it('previews the transactions a group of conditions would match', function () {
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account);

    $this->actingAs($this->user)
        ->postJson(route('ai.rule-suggestions.preview'), [
            'conditions' => [
                ['match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'mercadona'],
                ['match_field' => 'description', 'match_operator' => 'contains', 'match_token' => 'unique merchant 43'],
            ],
        ])
        ->assertOk()
        ->assertJson(['match_count' => 7, 'total_uncategorized' => 50]);
});

it('accepts suggestions and applies them immediately during onboarding', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account);
    fakeGeneratorReturning($groceries->id);

    $generated = $this->actingAs($this->user)->postJson(route('ai.rule-suggestions.generate'))->json();
    $ids = collect($generated['suggestions'][0]['values'])->pluck('id')->all();

    $this->actingAs($this->user)
        ->postJson(route('ai.rule-suggestions.accept'), [
            'suggestions' => [[
                'ids' => $ids,
                'values' => [[
                    'match_field' => 'creditor_name',
                    'match_operator' => 'equals',
                    'match_token' => 'mercadona',
                ]],
                'proposed_category_id' => $groceries->id,
            ]],
        ])
        ->assertOk()
        ->assertJson([
            'summary' => ['rules_created' => 1, 'transactions_categorized' => 6],
            'applied_to_existing' => true,
        ]);

    expect(AutomationRule::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(Transaction::query()->where('user_id', $this->user->id)->where('creditor_name', 'MERCADONA')->whereNotNull('category_id')->count())->toBe(6);
});

it('does not flag an upgrade when subscriptions are disabled', function () {
    config()->set('subscriptions.enabled', false);

    $this->actingAs($this->user)
        ->getJson(route('ai.rule-suggestions.show'))
        ->assertOk()
        ->assertJson(['requires_upgrade' => false]);
});

it('flags an upgrade for free users without a connected account', function () {
    config()->set('subscriptions.enabled', true);

    $this->actingAs($this->user)
        ->getJson(route('ai.rule-suggestions.show'))
        ->assertOk()
        ->assertJson(['requires_upgrade' => true]);
});

it('does not flag an upgrade when the user already linked a bank', function () {
    config()->set('subscriptions.enabled', true);
    BankingConnection::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->getJson(route('ai.rule-suggestions.show'))
        ->assertOk()
        ->assertJson(['requires_upgrade' => false]);
});

it('does not flag an upgrade for subscribed users', function () {
    config()->set('subscriptions.enabled', true);
    $this->user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $this->actingAs($this->user)
        ->getJson(route('ai.rule-suggestions.show'))
        ->assertOk()
        ->assertJson(['requires_upgrade' => false]);
});

it('records and revokes consent', function () {
    $this->actingAs($this->user)
        ->postJson(route('ai.consent.store'))
        ->assertOk()
        ->assertJson(['consented' => true]);

    expect($this->user->hasActiveAiConsent())->toBeTrue();

    $this->actingAs($this->user)
        ->deleteJson(route('ai.consent.destroy'))
        ->assertOk()
        ->assertJson(['consented' => false]);

    expect($this->user->fresh()->hasActiveAiConsent())->toBeFalse();
});
