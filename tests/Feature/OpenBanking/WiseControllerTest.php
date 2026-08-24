<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fakeWiseApi(): void
{
    Http::fake(function (Request $request) {
        $url = $request->url();

        if (str_contains($url, '/v1/profiles')) {
            return Http::response([
                ['id' => 36875276, 'type' => 'personal', 'details' => []],
                ['id' => 87413525, 'type' => 'business', 'details' => ['name' => 'Day Zero LLC']],
            ]);
        }

        if (str_contains($url, '/v2/borderless-accounts')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $profileId = (int) ($query['profileId'] ?? 0);

            if ($profileId === 36875276) {
                return Http::response([[
                    'id' => 44333087,
                    'profileId' => 36875276,
                    'balances' => [
                        ['currency' => 'EUR', 'amount' => ['value' => 19.81]],
                        ['currency' => 'USD', 'amount' => ['value' => 0]],
                    ],
                ]]);
            }

            if ($profileId === 87413525) {
                return Http::response([[
                    'id' => 67770905,
                    'profileId' => 87413525,
                    'balances' => [
                        ['currency' => 'EUR', 'amount' => ['value' => 100]],
                        ['currency' => 'MXN', 'amount' => ['value' => 50]],
                    ],
                ]]);
            }
        }

        return Http::response([], 404);
    });
}

test('connecting wise builds pending accounts for every profile using the profile id', function () {
    Queue::fake();
    fakeWiseApi();

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/wise/connect', [
        'api_token' => 'valid-wise-api-token-12345',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'wise')->first();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);

    $uids = collect($connection->pending_accounts_data)->pluck('uid');

    // Both profiles are represented...
    expect($uids)->toContain('36875276:EUR', '36875276:USD', '87413525:EUR', '87413525:MXN');
    expect($connection->pending_accounts_data)->toHaveCount(4);

    // ...and uids use the profile id, never the borderless-account id (the old bug).
    expect($uids->filter(fn ($uid) => str_starts_with($uid, '44333087') || str_starts_with($uid, '67770905')))
        ->toBeEmpty();

    // No accounts created yet for an onboarded user (mapping step does that).
    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);
});

test('connecting wise during onboarding auto-creates accounts for both profiles', function () {
    Queue::fake();
    fakeWiseApi();

    $user = User::factory()->create(); // not onboarded

    $response = $this->actingAs($user)->postJson('/open-banking/wise/connect', [
        'api_token' => 'valid-wise-api-token-12345',
    ]);

    $response->assertOk();

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'wise')->first();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);

    foreach (['36875276:EUR', '36875276:USD', '87413525:EUR', '87413525:MXN'] as $uid) {
        $this->assertDatabaseHas('accounts', [
            'user_id' => $user->id,
            'banking_connection_id' => $connection->id,
            'external_account_id' => $uid,
        ]);
    }

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('invalid wise token returns 422', function () {
    Http::fake([
        'api.wise.com/v1/profiles' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)->postJson('/open-banking/wise/connect', [
        'api_token' => 'invalid-wise-token-12345',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonFragment(['message' => 'Invalid API token or failed to connect to Wise.']);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'wise',
    ]);
});
