<?php

use App\Jobs\CategorizeOnboardingTransactionsJob;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('returns categories and transactions props on onboarding index', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $bank = Bank::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'bank_id' => $bank->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    // One uncategorized and one categorized transaction
    $uncategorized = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->has('categories', 1)
            ->has('transactions', 1)
            ->where('transactions.0.id', $uncategorized->id)
        );
});

it('returns only uncategorized transactions in the transactions prop', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $bank = Bank::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'bank_id' => $bank->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->count(3)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);
    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 3)
        );
});

it('does not return transactions belonging to other users', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $other = User::factory()->create(['onboarded_at' => null]);
    $bank = Bank::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id, 'bank_id' => $bank->id]);

    Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 0)
        );
});

it('lands directly on the connections step when ?step=create-account is requested', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $response = $this->actingAs($user)->get('/onboarding?step=create-account');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->where('initialStep', 'create-account')
        );
});

it('ignores an unknown step and falls back to the default flow', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $response = $this->actingAs($user)->get('/onboarding?step=not-a-real-step');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->where('initialStep', null)
        );
});

// The batch is queued when the user leaves the AI suggestions step, so by the
// time they finish onboarding it has been running for two or three steps.
it('marks the user onboarded without queueing the AI categorization batch on complete', function () {
    Queue::fake();

    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)
        ->post('/onboarding/complete')
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->onboarded_at)->not->toBeNull();

    Queue::assertNotPushed(CategorizeOnboardingTransactionsJob::class);
});

it('queues the AI categorization batch when the user leaves the AI suggestions step', function () {
    Queue::fake();

    $user = User::factory()->create(['onboarded_at' => null]);
    $user->recordAiConsent();

    $this->actingAs($user)
        ->post('/onboarding/categorize')
        ->assertOk()
        ->assertJson(['queued' => true]);

    Queue::assertPushed(
        CategorizeOnboardingTransactionsJob::class,
        fn (CategorizeOnboardingTransactionsJob $job): bool => $job->user->is($user),
    );
});

it('does not queue the AI categorization batch without a paid plan', function () {
    Queue::fake();
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create(['onboarded_at' => null]);
    $user->recordAiConsent();

    $this->actingAs($user)
        ->post('/onboarding/categorize')
        ->assertOk()
        ->assertJson(['queued' => false]);

    Queue::assertNotPushed(CategorizeOnboardingTransactionsJob::class);
});

it('does not queue the AI categorization batch without AI consent', function () {
    Queue::fake();

    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)
        ->post('/onboarding/categorize')
        ->assertOk()
        ->assertJson(['queued' => false]);

    Queue::assertNotPushed(CategorizeOnboardingTransactionsJob::class);
});

it('returns banks and accounts props on onboarding index', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $globalBank = Bank::factory()->create(['user_id' => null]);
    $userBank = Bank::factory()->create(['user_id' => $user->id]);
    $otherBank = Bank::factory()->create(['user_id' => User::factory()->create()->id]);

    $response = $this->actingAs($user)->get('/onboarding');

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/index')
            ->has('banks', 2) // global + user's own bank
            ->has('accounts')
        );
});

// The accounts hub renders the chooser for a bank that returned more than one
// account, so the connection waiting on that answer has to reach the page.
it('offers a connection awaiting its account mapping to the hub', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $connection = BankingConnection::factory()->for($user)->awaitingMapping()->create([
        'aspsp_name' => 'BBVA',
        'aspsp_logo' => 'https://logos.test/bbva.png',
        'pending_accounts_data' => [
            [
                'uid' => 'ext-1',
                'name' => 'Cuenta Nomina',
                'currency' => 'EUR',
                'account_id' => ['iban' => 'ES1234567890123456789012'],
            ],
            // No uid, so the provider gave us nothing to sync it by: it is not a
            // choice the user can make and never reaches the screen.
            ['name' => 'Sin identificador', 'currency' => 'EUR'],
        ],
    ]);

    $this->actingAs($user)
        ->get('/onboarding')
        ->assertInertia(fn ($page) => $page
            ->where('pendingMapping.connection_id', $connection->id)
            ->where('pendingMapping.bank_name', 'BBVA')
            ->where('pendingMapping.bank_logo', 'https://logos.test/bbva.png')
            ->count('pendingMapping.accounts', 1)
            ->where('pendingMapping.accounts.0.uid', 'ext-1')
            ->where('pendingMapping.accounts.0.name', 'Cuenta Nomina')
            ->where('pendingMapping.accounts.0.iban', 'ES1234567890123456789012')
            ->etc()
        );
});

it('offers no mapping when every connection is settled', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    BankingConnection::factory()->for($user)->create(['pending_accounts_data' => null]);

    $this->actingAs($user)
        ->get('/onboarding')
        ->assertInertia(fn ($page) => $page->where('pendingMapping', null)->etc());
});
