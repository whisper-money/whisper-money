<?php

use App\Enums\BankingConnectionStatus;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Bank::factory()->create([
        'name' => 'Interactive Brokers',
        'user_id' => null,
        'logo' => '/images/banks/logos/interactive-brokers.png',
    ]);
});

function ibFakeFlex(array $accountIds = ['U1234567']): void
{
    $statements = '';

    foreach ($accountIds as $accountId) {
        $statements .= '<FlexStatement accountId="'.$accountId.'">'
            .'<AccountInformation accountId="'.$accountId.'" currency="USD" />'
            .'<EquitySummaryInBase><EquitySummaryByReportDateInBase reportDate="20250115" cash="0" total="10000.00" /></EquitySummaryInBase>'
            .'</FlexStatement>';
    }

    Http::fake([
        '*SendRequest*' => Http::response('<FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>'),
        '*GetStatement*' => Http::response('<FlexQueryResponse queryName="Whisper" type="AF"><FlexStatements count="1">'.$statements.'</FlexStatements></FlexQueryResponse>'),
    ]);
}

function ibConnect(): array
{
    return ['token' => 'flex-token-1234567890', 'query_id' => '123456'];
}

test('users can connect with valid flex credentials', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    ibFakeFlex();

    $response = $this->actingAs($user)->postJson('/open-banking/interactive-brokers/connect', ibConnect());

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'interactivebrokers')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->api_secret)->toBe('123456');
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['uid'])->toBe('U1234567');
    expect($connection->pending_accounts_data[0]['name'])->toBe('Interactive Brokers (U1234567)');
    expect($connection->pending_accounts_data[0]['currency'])->toBe('USD');

    Queue::assertNothingPushed();
});

test('invalid flex credentials return 422', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake([
        '*SendRequest*' => Http::response('<FlexStatementResponse><Status>Fail</Status><ErrorCode>1015</ErrorCode><ErrorMessage>Invalid token.</ErrorMessage></FlexStatementResponse>'),
    ]);

    $response = $this->actingAs($user)->postJson('/open-banking/interactive-brokers/connect', ibConnect());

    $response->assertUnprocessable();

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'interactivebrokers',
    ]);
});

test('free tier users cannot connect after onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $response = $this->actingAs($user)->postJson('/open-banking/interactive-brokers/connect', ibConnect());

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);
});

test('token and query_id are required', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user)->postJson('/open-banking/interactive-brokers/connect', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['token', 'query_id']);
});

test('auto-creates accounts during onboarding', function () {
    config(['subscriptions.enabled' => true]);
    Queue::fake();

    $user = User::factory()->notOnboarded()->create();
    ibFakeFlex(['U1111111', 'U2222222']);

    $response = $this->actingAs($user)->postJson('/open-banking/interactive-brokers/connect', ibConnect());

    $response->assertOk();
    $response->assertJsonPath('redirect_url', route('onboarding', ['step' => 'create-account']));

    $connection = BankingConnection::where('user_id', $user->id)->where('provider', 'interactivebrokers')->first();

    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->pending_accounts_data)->toBeNull();

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'U1111111',
        'type' => 'investment',
    ]);
    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'U2222222',
        'type' => 'investment',
    ]);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('requires authentication', function () {
    $this->postJson('/open-banking/interactive-brokers/connect', [
        'token' => 'flex-token-1234567890',
        'query_id' => '123456',
    ])->assertUnauthorized();
});

test('reports a friendly message while the statement is still generating', function () {
    Sleep::fake();

    $user = User::factory()->onboarded()->create();
    Http::fake([
        '*SendRequest*' => Http::response('<FlexStatementResponse><Status>Success</Status><ReferenceCode>999</ReferenceCode></FlexStatementResponse>'),
        '*GetStatement*' => Http::response('<FlexStatementResponse><Status>Warn</Status><ErrorCode>1019</ErrorCode><ErrorMessage>Statement generation in progress. Please try again shortly.</ErrorMessage></FlexStatementResponse>'),
    ]);

    $this->actingAs($user)->postJson('/open-banking/interactive-brokers/connect', ibConnect())
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Interactive Brokers is still preparing your statement. Please try again in a moment.']);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'interactivebrokers',
    ]);
});

test('reports a rate-limit message when IB throttles the request', function () {
    $user = User::factory()->onboarded()->create();
    Http::fake([
        '*SendRequest*' => Http::response('<FlexStatementResponse><Status>Fail</Status><ErrorCode>1018</ErrorCode><ErrorMessage>Too many requests have been made from this token.</ErrorMessage></FlexStatementResponse>'),
    ]);

    $this->actingAs($user)->postJson('/open-banking/interactive-brokers/connect', ibConnect())
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Interactive Brokers is rate limiting requests. Please wait a few minutes and try again.']);
});
