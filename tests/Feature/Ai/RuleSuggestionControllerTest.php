<?php

use App\Enums\SuggestionRunStatus;
use App\Features\AiRuleSuggestions;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\SuggestionRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config()->set('ai_suggestions.eligibility_min_transactions', 50);
    config()->set('ai_suggestions.confidence_floor', 0.7);
    config()->set('ai_suggestions.overbroad_fraction', 0.4);

    $this->user = User::factory()->notOnboarded()->create();
    $this->account = Account::factory()->for($this->user)->create();
    Feature::for($this->user)->activate(AiRuleSuggestions::class);
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

it('blocks generation when the feature flag is off', function () {
    Feature::for($this->user)->deactivate(AiRuleSuggestions::class);

    $this->actingAs($this->user)
        ->postJson(route('ai.rule-suggestions.generate'))
        ->assertForbidden();
});

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
        ->assertJsonPath('suggestions.0.match_token', 'mercadona');

    expect($response->json('suggestions.0.proposed_category.id'))->toBe($groceries->id);
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

it('previews the transactions a token would match', function () {
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account);

    $this->actingAs($this->user)
        ->getJson(route('ai.rule-suggestions.preview', [
            'match_field' => 'creditor_name', 'match_operator' => 'equals', 'match_token' => 'mercadona',
        ]))
        ->assertOk()
        ->assertJson(['match_count' => 6, 'total_uncategorized' => 50]);
});

it('accepts suggestions and applies them immediately during onboarding', function () {
    $groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);
    $this->user->recordAiConsent();
    seedTransactions($this->user, $this->account);
    fakeGeneratorReturning($groceries->id);

    $generated = $this->actingAs($this->user)->postJson(route('ai.rule-suggestions.generate'))->json();
    $suggestionId = $generated['suggestions'][0]['id'];

    $this->actingAs($this->user)
        ->postJson(route('ai.rule-suggestions.accept'), [
            'suggestions' => [[
                'id' => $suggestionId,
                'match_field' => 'creditor_name',
                'match_operator' => 'equals',
                'match_token' => 'mercadona',
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
