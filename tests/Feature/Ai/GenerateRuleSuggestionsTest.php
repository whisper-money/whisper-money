<?php

use App\Enums\SuggestionRunStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\SuggestionRun;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use App\Services\Ai\GenerateRuleSuggestions;

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

    for ($i = 0; $i < 20; $i++) {
        Transaction::factory()->for($this->user)->create([
            'account_id' => $this->account->id, 'category_id' => null, 'description_iv' => null,
            'creditor_name' => null, 'description' => "UNIQUE MERCHANT {$i}", 'amount' => -1000,
        ]);
    }
});

/**
 * The generating screen shows these two numbers while the run is still going,
 * so they have to be on the row before the slow half starts — a counter that
 * only lands with the terminal status is a counter nobody ever sees.
 */
it('saves what it grouped before the model is asked for anything', function () {
    $run = SuggestionRun::factory()->for($this->user)->create(['status' => SuggestionRunStatus::Pending]);

    $observed = new ArrayObject;

    app()->instance(RuleSuggestionGenerator::class, new class($this->groceries->id, $run->id, $observed) implements RuleSuggestionGenerator
    {
        public function __construct(
            private string $categoryId,
            private string $runId,
            private ArrayObject $observed,
        ) {}

        public function generate(array $groups, array $categoryOptions): array
        {
            // Read back from the database, not from the instance in memory:
            // this is exactly what the polling client would see mid-run.
            $this->observed['run'] = SuggestionRun::query()->find($this->runId);

            return [[
                'group_key' => 'mercadona', 'match_field' => 'creditor_name', 'match_operator' => 'equals',
                'match_token' => 'mercadona', 'category_id' => $this->categoryId, 'confidence' => 0.95,
            ]];
        }
    });

    app(GenerateRuleSuggestions::class)->run($run);

    $midRun = $observed['run'];

    expect($midRun->status)->toBe(SuggestionRunStatus::Processing)
        ->and($midRun->merchants_considered)->toBeGreaterThan(0)
        ->and($midRun->transactions_considered)->toBeGreaterThan(0)
        ->and($run->refresh()->merchants_considered)->toBe($midRun->merchants_considered)
        ->and($run->suggestions_count)->toBe(1);
});

it('keeps the counts on a run that found nothing worth suggesting', function () {
    $run = SuggestionRun::factory()->for($this->user)->create(['status' => SuggestionRunStatus::Pending]);

    app()->instance(RuleSuggestionGenerator::class, new class implements RuleSuggestionGenerator
    {
        public function generate(array $groups, array $categoryOptions): array
        {
            return [];
        }
    });

    app(GenerateRuleSuggestions::class)->run($run);

    expect($run->refresh()->status)->toBe(SuggestionRunStatus::Empty)
        ->and($run->merchants_considered)->toBeGreaterThan(0)
        ->and($run->suggestions_count)->toBe(0);
});
