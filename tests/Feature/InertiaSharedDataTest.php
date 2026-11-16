<?php

use App\Enums\BankingConnectionStatus;
use App\Features\CashflowAvailableFree;
use App\Features\CustomDashboards;
use App\Features\TransactionSplitting;
use App\Jobs\PurgeResidualEncryptionArtifactsJob;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

test('guests receive null auth user in shared props', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null)
    );
});

test('authenticated users receive auth user in shared props', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Madrid']);

    $response = actingAs($user)->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.id', $user->id)
        ->where('auth.user.email', $user->email)
        ->where('auth.user.timezone', 'Europe/Madrid')
    );
});

test('shared auth user does not expose sensitive fields', function () {
    $user = User::factory()->onboarded()->create([
        'stripe_id' => 'cus_test123',
        'pm_type' => 'card',
        'pm_last_four' => '4242',
        'trial_ends_at' => now()->addDays(7),
        'encryption_salt' => str_repeat('a', 24),
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('auth.user.email', $user->email)
        ->missing('auth.user.stripe_id')
        ->missing('auth.user.pm_type')
        ->missing('auth.user.pm_last_four')
        ->missing('auth.user.trial_ends_at')
        ->missing('auth.user.encryption_salt')
        ->missing('auth.user.password')
        ->missing('auth.user.two_factor_secret')
        ->missing('auth.user.two_factor_recovery_codes')
        ->missing('auth.user.remember_token')
    );
});

test('a web GET does not mutate the user inline but queues the encryption cleanup', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create([
        'encryption_salt' => str_repeat('a', 24),
    ]);

    actingAs($user)->withoutVite()->get(route('dashboard'))->assertSuccessful();

    // Rendering the page must stay read-only: the salt is untouched inline.
    expect($user->fresh()->encryption_salt)->toBe(str_repeat('a', 24));

    // The eventual cleanup is handed off to the queued job instead.
    Queue::assertPushed(
        PurgeResidualEncryptionArtifactsJob::class,
        fn (PurgeResidualEncryptionArtifactsJob $job) => $job->user->is($user),
    );
});

test('a web GET does not queue encryption cleanup when the user has no salt', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['encryption_salt' => null]);

    actingAs($user)->withoutVite()->get(route('dashboard'))->assertSuccessful();

    Queue::assertNotPushed(PurgeResidualEncryptionArtifactsJob::class);
});

test('all pages receive app url in shared props', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('appUrl', config('app.url'))
    );
});

test('shared feature flags do not include coinbase flag', function () {
    $response = $this->withoutVite()->get(route('home'));

    $props = $response->viewData('page')['props'];

    expect($props['features'])->toBe([
        'cashflow' => true,
        'transactionSplitting' => true,
        'recurringTransactions' => false,
        'cashflowAvailableFree' => false,
        'fullImport' => false,
        'fullImportSettings' => false,
        'customDashboards' => false,
    ]);
});

test('the full import stays closed by default, even to a user inside the onboarding window', function () {
    $user = User::factory()->create(['onboarded_at' => now()->subDay()]);

    actingAs($user)->withoutVite()->get(route('settings.connections.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.fullImport', false)
            ->where('features.fullImportSettings', false)
        );
});

test('authenticated users receive their transaction splitting flag value', function () {
    $user = User::factory()->onboarded()->create();
    Feature::for($user)->deactivate(TransactionSplitting::class);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('features.transactionSplitting', false)
    );
});

test('shared feature flags reflect the cashflow available flag per user', function (bool $active) {
    $user = User::factory()->onboarded()->create();

    if ($active) {
        Feature::for($user)->activate(CashflowAvailableFree::class);
    }

    actingAs($user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.cashflowAvailableFree', $active)
        );
})->with(['active' => true, 'inactive' => false]);

test('shared feature flags reflect the custom dashboards flag per user', function (bool $active) {
    $user = User::factory()->onboarded()->create();

    if ($active) {
        Feature::for($user)->activate(CustomDashboards::class);
    }

    actingAs($user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('features.customDashboards', $active)
        );
})->with(['active' => true, 'inactive' => false]);

test('authenticated users receive subscription payment issue when subscription is past due', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_past_due_test123',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_test123',
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('subscriptionPaymentIssue.status', 'past_due')
        ->where('subscriptionPaymentIssue.action_url', route('settings.billing.portal'))
    );
});

test('authenticated users do not receive subscription payment issue when subscription is active', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_active_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('subscriptionPaymentIssue', null)
    );
});

test('bank connection issues list expired and stuck connections with a way to reconnect', function () {
    $user = User::factory()->onboarded()->create();
    $consentLapsed = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Santander',
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->subDay(),
    ]);
    $flaggedExpired = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'BBVA',
    ]);
    $outOfRetries = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'ING',
        'valid_until' => now()->addMonth(),
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1,
    ]);
    $apiKeyFailed = BankingConnection::factory()->kraken()->error()->create([
        'user_id' => $user->id,
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES,
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('bankConnectionIssues', 4)
        ->where('bankConnectionIssues.0.id', $flaggedExpired->id)
        ->where('bankConnectionIssues.0.reason', 'expired')
        ->where('bankConnectionIssues.1.id', $outOfRetries->id)
        ->where('bankConnectionIssues.1.reason', 'error')
        ->where('bankConnectionIssues.1.reconnect_url', route('open-banking.reconnect', $outOfRetries))
        ->where('bankConnectionIssues.2.id', $apiKeyFailed->id)
        ->where('bankConnectionIssues.2.aspsp_name', 'Kraken')
        ->where('bankConnectionIssues.2.provider', 'kraken')
        ->where('bankConnectionIssues.2.reason', 'error')
        ->where('bankConnectionIssues.2.reconnect_url', null)
        ->where('bankConnectionIssues.3.id', $consentLapsed->id)
        ->where('bankConnectionIssues.3.aspsp_name', 'Santander')
        ->where('bankConnectionIssues.3.reason', 'expired')
        ->where('bankConnectionIssues.3.reconnect_url', route('open-banking.reconnect', $consentLapsed))
    );
});

test('an errored bank connection whose consent has also lapsed reads as expired', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'valid_until' => now()->subDay(),
    ]);

    actingAs($user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bankConnectionIssues', 1)
            ->where('bankConnectionIssues.0.reason', 'expired')
        );
});

test('bank connection issues leave out healthy, recovering, unfinished and disconnected connections', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->create([
        'user_id' => $user->id,
        'valid_until' => now()->addDay(),
    ]);
    BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'valid_until' => now()->addMonth(),
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES - 1,
    ]);
    BankingConnection::factory()->revoked()->create([
        'user_id' => $user->id,
        'valid_until' => now()->subDay(),
    ]);
    BankingConnection::factory()->pending()->create(['user_id' => $user->id]);
    BankingConnection::factory()->awaitingMapping()->create(['user_id' => $user->id]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->has('bankConnectionIssues', 0));
});

test('bank connection issues never include another user\'s connections', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->expired()->create();
    BankingConnection::factory()->error()->create([
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1,
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->has('bankConnectionIssues', 0));
});

test('bank connection issues leave out a shared space member\'s connections, as the connections page does', function () {
    $owner = User::factory()->onboarded()->create();
    $member = User::factory()->onboarded()->create();
    $household = Space::factory()->create(['owner_id' => $owner->id]);
    $household->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
    $member->forceFill(['current_space_id' => $household->id])->save();
    BankingConnection::factory()->expired()->create([
        'user_id' => $owner->id,
        'space_id' => $household->id,
    ]);
    $own = BankingConnection::factory()->expired()->create(['user_id' => $member->id]);

    actingAs($member)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('bankConnectionIssues', 1)
            ->where('bankConnectionIssues.0.id', $own->id)
        );
});

test('authenticated users receive their banking connections in shared props', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Bankinter',
        'status' => BankingConnectionStatus::Active,
    ]);

    $response = actingAs($user)->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('bankingConnections', 1)
        ->where('bankingConnections.0.id', $connection->id)
        ->where('bankingConnections.0.aspsp_name', 'Bankinter')
        ->where('bankingConnections.0.provider', $connection->provider->value)
        ->where('bankingConnections.0.status', 'active')
    );
});

test('shared currency options split profile and account currencies', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('currencies.profile.0.code', 'USD')
        ->where('currencies.accounts.0.code', 'USD')
    );

    $props = $response->viewData('page')['props'];

    expect(collect($props['currencies']['profile'])->pluck('code'))->toContain('ARS');
    expect(collect($props['currencies']['profile'])->pluck('code'))->not->toContain('BTC');
    expect(collect($props['currencies']['accounts'])->pluck('code'))->toContain('BTC');
});

test('the translation dictionary ships on the first load', function () {
    $user = User::factory()->onboarded()->create(['locale' => 'es']);

    $props = actingAs($user)->withoutVite()->get(route('dashboard'))->viewData('page')['props'];

    expect($props['translations'])->toBeArray()->not->toBeEmpty();
});

test('the translation dictionary is omitted once the client already has it', function () {
    // It is ~250 KB in es/fr, so resending it on every navigation dominated
    // the payload. It is a once prop keyed by the locale and the lang file's
    // modification time: the client keeps its copy until either changes.
    $user = User::factory()->onboarded()->create(['locale' => 'es']);

    // The asset version and the dictionary's once key (locale and file mtime)
    // are only known once a response has been rendered.
    $page = actingAs($user)->withoutVite()->get(route('dashboard'))->viewData('page');
    $key = collect($page['onceProps'])->search(fn (array $meta): bool => $meta['prop'] === 'translations');

    expect($key)->toStartWith('translations:es:');

    $response = actingAs($user)
        ->withoutVite()
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $page['version'],
            'X-Inertia-Except-Once-Props' => $key,
        ])
        ->get(route('dashboard'));

    $response->assertOk();

    expect($response->json('props'))->not->toHaveKey('translations');
});

test('shares whether AI import mapping is enabled for the user', function (bool $enabled) {
    config()->set('ai_import_mapping.enabled', $enabled);
    config()->set('services.typesafe.enabled', true);

    $response = actingAs(User::factory()->onboarded()->create())->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('aiImportMappingEnabled', $enabled));
})->with([true, false]);

test('AI import mapping is off for every user with the shipped defaults', function () {
    config()->set('ai_import_mapping', require config_path('ai_import_mapping.php'));
    config()->set('services.typesafe', (require config_path('services.php'))['typesafe']);

    $response = actingAs(User::factory()->onboarded()->create())->withoutVite()->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page->where('aiImportMappingEnabled', false));
});

test('guests never get AI import mapping', function () {
    $response = $this->withoutVite()->get(route('home'));

    $response->assertInertia(fn (Assert $page) => $page->where('aiImportMappingEnabled', false));
});
