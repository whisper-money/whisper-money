<?php

use App\Enums\SuggestionRunStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;

beforeEach(function () {
    config()->set('ai_suggestions.confidence_floor', 0.7);
    config()->set('ai_suggestions.overbroad_fraction', 0.4);

    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create();
    $this->groceries = Category::factory()->for($this->user)->create(['name' => 'Groceries', 'type' => 'expense']);

    for ($i = 0; $i < 6; $i++) {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->account->id, 'category_id' => null, 'description_iv' => null,
            'creditor_name' => 'MERCADONA', 'description' => "MERCADONA {$i}", 'amount' => -4000,
        ]);
    }

    // Filler so the "mercadona" token matches well under the over-broad threshold.
    for ($i = 0; $i < 20; $i++) {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->account->id, 'category_id' => null, 'description_iv' => null,
            'creditor_name' => null, 'description' => "UNIQUE MERCHANT {$i}", 'amount' => -1000,
        ]);
    }

    app()->instance(RuleSuggestionGenerator::class, new class($this->groceries->id) implements RuleSuggestionGenerator
    {
        public function __construct(private string $categoryId) {}

        public function generate(array $groups, array $categoryOptions): array
        {
            return [[
                'group_key' => 'mercadona', 'match_field' => 'creditor_name', 'match_operator' => 'equals',
                'match_token' => 'mercadona', 'category_id' => $this->categoryId, 'confidence' => 0.95,
            ]];
        }
    });
});

it('prints the pipeline output for a user (dry run)', function () {
    $this->artisan('ai:suggest-rules', ['user' => $this->user->email])
        ->expectsOutputToContain('mercadona')
        ->expectsOutputToContain('survived the guards')
        ->assertSuccessful();

    expect($this->user->suggestionRuns()->count())->toBe(0);
});

it('persists a run with --persist', function () {
    $this->artisan('ai:suggest-rules', ['user' => $this->user->id, '--persist' => true])
        ->assertSuccessful();

    $run = $this->user->suggestionRuns()->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(SuggestionRunStatus::Completed)
        ->and($run->suggestions()->count())->toBe(1);
});

it('fails for an unknown user', function () {
    $this->artisan('ai:suggest-rules', ['user' => 'nobody@example.com'])
        ->expectsOutputToContain('User not found.')
        ->assertFailed();
});
