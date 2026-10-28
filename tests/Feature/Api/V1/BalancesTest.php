<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

describe('list', function () {
    it('lists the balances of an account, the most recent day first, page by page', function () {
        $user = User::factory()->create();
        $account = Account::factory()->withBalance()->create(['user_id' => $user->id]);
        $balances = collect(['2026-03-01', '2026-01-01', '2026-02-01'])->map(fn (string $date): AccountBalance => AccountBalance::factory()->create([
            'account_id' => $account->id,
            'balance_date' => $date,
        ]));
        AccountBalance::factory()->create(['balance_date' => '2026-04-01']);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson("/api/v1/accounts/{$account->id}/balances?limit=2")
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.balance_date', ['2026-03-01', '2026-02-01'])
            ->assertJsonPath('data.0', [
                'id' => $balances[0]->id,
                'account_id' => $account->id,
                'balance_date' => '2026-03-01',
                'balance' => $balances[0]->balance,
                'invested_amount' => null,
                'derived' => false,
            ]);

        withHeaders($headers)->getJson("/api/v1/accounts/{$account->id}/balances?limit=2&starting_after={$balances[2]->id}")
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$balances[1]->id]);
    });

    it('answers 404 for an account of another user', function () {
        $account = Account::factory()->create();
        AccountBalance::factory()->create(['account_id' => $account->id]);

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson("/api/v1/accounts/{$account->id}/balances")
            ->assertNotFound();
    });

    it('lists the balances of an account in another space when asked', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
        $space->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);
        $account = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        AccountBalance::factory()->create(['account_id' => $account->id]);

        withHeaders(apiV1Bearer($user))->getJson("/api/v1/accounts/{$account->id}/balances")->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->getJson("/api/v1/accounts/{$account->id}/balances?space={$space->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    });
});

describe('record', function () {
    it('records a balance on a manual account, then replaces it for the same day', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Investment]);
        $headers = apiV1Bearer($user, ['api:read', 'api:write']);

        withHeaders($headers)
            ->postJson("/api/v1/accounts/{$account->id}/balances", ['balance' => 250_000, 'balance_date' => '2026-01-31', 'invested_amount' => 200_000])
            ->assertCreated()
            ->assertJsonPath('data.balance', 250_000)
            ->assertJsonPath('data.balance_date', '2026-01-31')
            ->assertJsonPath('data.invested_amount', 200_000)
            ->assertJsonPath('data.derived', false);

        withHeaders($headers)
            ->postJson("/api/v1/accounts/{$account->id}/balances", ['balance' => 260_000, 'balance_date' => '2026-01-31'])
            ->assertOk()
            ->assertJsonPath('data.balance', 260_000)
            ->assertJsonPath('data.invested_amount', 200_000);

        expect($account->balances()->count())->toBe(1);
    });

    it('defaults the date to today and takes over a derived snapshot', function () {
        $user = User::factory()->create();
        $account = Account::factory()->withBalance()->create(['user_id' => $user->id]);
        AccountBalance::factory()->derived()->create(['account_id' => $account->id, 'balance_date' => now()->toDateString()]);

        withHeaders(apiV1Bearer($user, ['api:read', 'api:write']))
            ->postJson("/api/v1/accounts/{$account->id}/balances", ['balance' => 1_000])
            ->assertOk()
            ->assertJsonPath('data.balance_date', now()->toDateString())
            ->assertJsonPath('data.derived', false);
    });

    it('validates the balance', function () {
        $user = User::factory()->create();
        $account = Account::factory()->withBalance()->create(['user_id' => $user->id]);

        withHeaders(apiV1Bearer($user, ['api:read', 'api:write']))
            ->postJson("/api/v1/accounts/{$account->id}/balances", ['balance' => 'lots', 'balance_date' => '262004-08-10'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['balance', 'balance_date']);
    });

    it('refuses a connected account and a credit card', function (string $state, string $reason) {
        $user = User::factory()->create();
        $account = Account::factory()->{$state}()->create(['user_id' => $user->id]);

        withHeaders(apiV1Bearer($user, ['api:read', 'api:write']))
            ->postJson("/api/v1/accounts/{$account->id}/balances", ['balance' => 1_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account' => $reason]);

        expect($account->balances()->count())->toBe(0);
    })->with([
        'connected' => ['connected', 'connected to a bank'],
        'credit card' => ['creditCard', 'credit limit'],
    ]);

    it('answers 404 for an account of another user', function () {
        $account = Account::factory()->withBalance()->create();

        withHeaders(apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']))
            ->postJson("/api/v1/accounts/{$account->id}/balances", ['balance' => 1_000])
            ->assertNotFound();

        expect($account->balances()->count())->toBe(0);
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();
        $account = Account::factory()->withBalance()->create(['user_id' => $user->id]);

        withHeaders(apiV1Bearer($user))
            ->postJson("/api/v1/accounts/{$account->id}/balances", ['balance' => 1_000])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
    });

    it('replays a POST with the same idempotency key', function () {
        $user = User::factory()->create();
        $account = Account::factory()->withBalance()->create(['user_id' => $user->id]);
        $headers = [...apiV1Bearer($user, ['api:read', 'api:write']), 'Idempotency-Key' => 'balance-1'];
        $body = ['balance' => 1_000, 'balance_date' => '2026-01-31'];

        $first = withHeaders($headers)->postJson("/api/v1/accounts/{$account->id}/balances", $body)->assertCreated();

        withHeaders($headers)->postJson("/api/v1/accounts/{$account->id}/balances", $body)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
    });
});
