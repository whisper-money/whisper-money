<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->evalStorage = sys_get_temp_dir().'/ai-eval-test-'.uniqid();
    $this->app->useStoragePath($this->evalStorage);
    Sleep::fake();
    Http::preventStrayRequests();
    TransactionCategorizationAgent::fake()->preventStrayPrompts();

    $this->user = User::factory()->create();
    $this->user->recordAiConsent();

    $this->groceries = Category::factory()->for($this->user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
    $this->restaurants = Category::factory()->for($this->user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);

    $manual = fn (Category $category, string $description): Transaction => Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'category_source' => CategorySource::Manual,
        'amount' => -2500,
        'description' => $description,
    ]);

    $this->mercadona = $manual($this->groceries, 'mercadona compra');
    $this->burger = $manual($this->restaurants, 'burger king');

    $catalog = CategoryCatalog::forUser($this->user);
    $this->indexOf = fn (Category $category): int => (int) collect($catalog->options())
        ->firstWhere('path', $category->name)['index'];
});

afterEach(function () {
    if (isset($this->evalStorage)) {
        File::deleteDirectory($this->evalStorage);
    }
});

it('scores gemini against the categories users picked by hand without touching them', function () {
    $withoutConsent = User::factory()->create();
    $deleted = User::factory()->create();
    $deleted->recordAiConsent();

    foreach ([$withoutConsent, $deleted] as $excluded) {
        Transaction::factory()->create([
            'user_id' => $excluded->id,
            'category_id' => Category::factory()->for($excluded)->create()->id,
            'category_source' => CategorySource::Manual,
        ]);
    }

    $deleted->delete();

    TransactionCategorizationAgent::fake([
        ['results' => [
            ['ref' => $this->mercadona->id, 'category_index' => ($this->indexOf)($this->groceries), 'confidence' => 0.95, 'merchant_unambiguous' => true],
            ['ref' => $this->burger->id, 'category_index' => ($this->indexOf)($this->groceries), 'confidence' => 0.6, 'merchant_unambiguous' => false],
        ]],
    ])->preventStrayPrompts();

    $this->artisan('ai:categorization-eval', ['--backend' => ['gemini'], '--seed' => 1])
        ->expectsOutputToContain('2 transactions from 1 users')
        ->expectsConfirmation('Send them to the selected providers?', 'yes')
        ->expectsTable(['Confidence', 'gemini (n · precision)'], [
            ['0.00 – 0.50', '0 · —'],
            ['0.50 – 0.70', '1 · 0.0% (0/1)'],
            ['0.70 – 0.85', '0 · —'],
            ['0.85 – 0.95', '0 · —'],
            ['0.95 – 1.00', '1 · 100.0% (1/1)'],
        ])
        ->assertSuccessful();

    expect($this->burger->fresh()->category_id)->toBe($this->restaurants->id)
        ->and($this->burger->fresh()->ai_suggested_category_id)->toBeNull();

    $csv = File::files(storage_path('app/private/ai-eval'))[0]->getContents();

    expect($csv)->toContain('gemini_correct')
        ->and(substr_count($csv, "\n"))->toBe(3);
});

it('prices jev by the tokens it reports, rate-limited retries included', function () {
    config()->set('services.typesafe.key', 'test-key');
    config()->set('services.typesafe.enabled', true);

    $answer = fn (Category $category): array => [
        'model' => 'jev-1.13.0',
        'answers' => [
            'category' => ['type' => 'choice', 'choice' => (string) ($this->indexOf)($category), 'confidence' => 0.9],
            'merchant_unambiguous' => ['type' => 'noul', 'noul' => 0.9],
        ],
        'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 3],
    ];

    Http::fakeSequence('api.typesafe.ai/*')
        ->push(['error' => 'rate limited'], 429)
        ->push($answer($this->restaurants))
        ->push($answer($this->restaurants));

    $this->artisan('ai:categorization-eval', ['--backend' => ['jev'], '--seed' => 1])
        ->expectsConfirmation('Send them to the selected providers?', 'yes')
        ->expectsOutputToContain('$0.0840')
        ->assertSuccessful();

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(1);
});

it('refuses to evaluate jev without an api key', function () {
    config()->set('services.typesafe.enabled', false);

    $this->artisan('ai:categorization-eval', ['--backend' => ['jev']])
        ->expectsOutputToContain('Jev needs TYPESAFE_API_KEY.')
        ->assertFailed();
});
