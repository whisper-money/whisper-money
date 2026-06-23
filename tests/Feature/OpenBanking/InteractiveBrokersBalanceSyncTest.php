<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\InteractiveBrokersBalanceSyncService;
use App\Services\Banking\InteractiveBrokersClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Build a Flex GetStatement (FlexQueryResponse) body. Each statement maps an
 * accountId to its daily NAV rows and (optional) open positions.
 *
 * @param  array<int, array{accountId: string, currency?: string, nav: array<string, array{total: float, cash: float}>, positions?: array<int, array{costBasisMoney: float, fxRateToBase: float}>}>  $statements
 */
function ibStatementXml(array $statements): string
{
    $statementsXml = '';

    foreach ($statements as $statement) {
        $currency = $statement['currency'] ?? 'USD';
        $navXml = '';

        foreach ($statement['nav'] as $date => $values) {
            $navXml .= sprintf(
                '<EquitySummaryByReportDateInBase reportDate="%s" cash="%s" total="%s" />',
                $date,
                $values['cash'],
                $values['total'],
            );
        }

        $positionsXml = '';

        foreach ($statement['positions'] ?? [] as $position) {
            $positionsXml .= sprintf(
                '<OpenPosition currency="%s" fxRateToBase="%s" costBasisMoney="%s" />',
                $currency,
                $position['fxRateToBase'],
                $position['costBasisMoney'],
            );
        }

        $positionsBlock = $positionsXml !== '' ? "<OpenPositions>{$positionsXml}</OpenPositions>" : '';

        $statementsXml .= <<<XML
        <FlexStatement accountId="{$statement['accountId']}" fromDate="20250101" toDate="20250115">
        <AccountInformation accountId="{$statement['accountId']}" currency="{$currency}" />
        <EquitySummaryInBase>{$navXml}</EquitySummaryInBase>
        {$positionsBlock}
        </FlexStatement>
        XML;
    }

    return <<<XML
    <FlexQueryResponse queryName="Whisper" type="AF">
    <FlexStatements count="1">{$statementsXml}</FlexStatements>
    </FlexQueryResponse>
    XML;
}

function ibSendRequestXml(string $referenceCode = '1234567890'): string
{
    return <<<XML
    <FlexStatementResponse timestamp="20250115;093000">
    <Status>Success</Status>
    <ReferenceCode>{$referenceCode}</ReferenceCode>
    <Url>https://ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService/GetStatement</Url>
    </FlexStatementResponse>
    XML;
}

function ibFlexError(string $code, string $message): string
{
    return <<<XML
    <FlexStatementResponse timestamp="20250115;093000">
    <Status>Fail</Status>
    <ErrorCode>{$code}</ErrorCode>
    <ErrorMessage>{$message}</ErrorMessage>
    </FlexStatementResponse>
    XML;
}

function ibAccount(User $user, BankingConnection $connection, string $externalId = 'U1234567'): Account
{
    return Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => $externalId,
        'currency_code' => 'USD',
    ]);
}

function ibSetup(): array
{
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->interactiveBrokers()->create(['user_id' => $user->id]);

    return [$user, $connection];
}

test('syncs daily NAV balances and stores invested amount on the latest date', function () {
    [$user, $connection] = ibSetup();
    $account = ibAccount($user, $connection);

    Http::fake([
        '*SendRequest*' => Http::response(ibSendRequestXml()),
        '*GetStatement*' => Http::response(ibStatementXml([[
            'accountId' => 'U1234567',
            'nav' => [
                '2025-01-14' => ['total' => 9500.00, 'cash' => 500.00],
                '2025-01-15' => ['total' => 10000.00, 'cash' => 500.00],
            ],
            'positions' => [
                ['costBasisMoney' => 8000.00, 'fxRateToBase' => 1],
                ['costBasisMoney' => 1000.00, 'fxRateToBase' => 0.72],
            ],
        ]])),
    ]);

    $accounts = (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
    app(InteractiveBrokersBalanceSyncService::class)->sync($account, $accounts);

    expect($account->balances()->count())->toBe(2);

    $latest = $account->balances()->orderBy('balance_date', 'desc')->first();
    expect($latest->balance)->toBe(1000000);
    // invested = costBasis (8000 + 1000*0.72 = 8720) + cash (500) = 9220
    expect($latest->invested_amount)->toBe(922000);
    // profit derives from balance - invested = 1000000 - 922000 = 78000
    expect($latest->balance - $latest->invested_amount)->toBe(78000);

    // Historical row carries balance only (no cost basis available for past days).
    $previous = $account->balances()->orderBy('balance_date', 'asc')->first();
    expect($previous->balance)->toBe(950000);
    expect($previous->invested_amount)->toBeNull();
});

test('parses the compact YYYYMMDD date format', function () {
    [$user, $connection] = ibSetup();
    $account = ibAccount($user, $connection);

    Http::fake([
        '*SendRequest*' => Http::response(ibSendRequestXml()),
        '*GetStatement*' => Http::response(ibStatementXml([[
            'accountId' => 'U1234567',
            'nav' => ['20250115' => ['total' => 10000.00, 'cash' => 0.00]],
        ]])),
    ]);

    $accounts = (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
    app(InteractiveBrokersBalanceSyncService::class)->sync($account, $accounts);

    expect($account->balances()->first()->balance_date->toDateString())->toBe('2025-01-15');
});

test('distributes a multi-account statement to each account', function () {
    [$user, $connection] = ibSetup();
    $first = ibAccount($user, $connection, 'U1111111');
    $second = ibAccount($user, $connection, 'U2222222');

    Http::fake([
        '*SendRequest*' => Http::response(ibSendRequestXml()),
        '*GetStatement*' => Http::response(ibStatementXml([
            ['accountId' => 'U1111111', 'nav' => ['2025-01-15' => ['total' => 10000.00, 'cash' => 0.00]]],
            ['accountId' => 'U2222222', 'nav' => ['2025-01-15' => ['total' => 25000.00, 'cash' => 0.00]]],
        ])),
    ]);

    $accounts = (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
    $service = app(InteractiveBrokersBalanceSyncService::class);
    $service->sync($first, $accounts);
    $service->sync($second, $accounts);

    expect($first->balances()->first()->balance)->toBe(1000000);
    expect($second->balances()->first()->balance)->toBe(2500000);
});

test('subsequent sync only processes entries since the last balance date', function () {
    [$user, $connection] = ibSetup();
    $account = ibAccount($user, $connection);
    $account->balances()->create(['balance_date' => '2025-01-14', 'balance' => 940000]);

    Http::fake([
        '*SendRequest*' => Http::response(ibSendRequestXml()),
        '*GetStatement*' => Http::response(ibStatementXml([[
            'accountId' => 'U1234567',
            'nav' => [
                '2025-01-10' => ['total' => 9000.00, 'cash' => 0.00],
                '2025-01-14' => ['total' => 9500.00, 'cash' => 0.00],
                '2025-01-15' => ['total' => 10000.00, 'cash' => 0.00],
            ],
        ]])),
    ]);

    $accounts = (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
    app(InteractiveBrokersBalanceSyncService::class)->sync($account, $accounts, isFirstSync: false);

    // The old 2025-01-10 row is skipped; 2025-01-14 is updated and 2025-01-15 added.
    expect($account->balances()->count())->toBe(2);
    expect($account->balances()->where('balance_date', '2025-01-14')->first()->balance)->toBe(950000);
});

test('polls GetStatement while the statement is still generating', function () {
    Sleep::fake();

    [$user, $connection] = ibSetup();
    $account = ibAccount($user, $connection);

    Http::fake([
        '*SendRequest*' => Http::response(ibSendRequestXml()),
        '*GetStatement*' => Http::sequence()
            ->push(ibFlexError('1019', 'Statement generation in progress. Please try again shortly.'))
            ->push(ibStatementXml([[
                'accountId' => 'U1234567',
                'nav' => ['2025-01-15' => ['total' => 10000.00, 'cash' => 0.00]],
            ]])),
    ]);

    $accounts = (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
    app(InteractiveBrokersBalanceSyncService::class)->sync($account, $accounts);

    expect($account->balances()->first()->balance)->toBe(1000000);
});

test('throws an auth error for an invalid token', function () {
    Http::fake([
        '*SendRequest*' => Http::response(ibFlexError('1015', 'Invalid token has been provided.')),
    ]);

    $client = new InteractiveBrokersClient('bad-token', '123456');

    try {
        $client->fetchStatement();
        $this->fail('Expected a RequestException');
    } catch (RequestException $e) {
        expect($e->response->status())->toBe(401);
    }
});

test('throws a rate-limit error when IB throttles the query', function () {
    Http::fake([
        '*SendRequest*' => Http::response(ibFlexError('1018', 'Too many requests have been made from this token.')),
    ]);

    $client = new InteractiveBrokersClient('token', '123456');

    try {
        $client->fetchStatement();
        $this->fail('Expected a RequestException');
    } catch (RequestException $e) {
        expect($e->response->status())->toBe(429);
    }
});

test('skips an account without an external_account_id', function () {
    [$user] = ibSetup();
    $account = Account::factory()->create(['user_id' => $user->id, 'external_account_id' => null]);

    app(InteractiveBrokersBalanceSyncService::class)->sync($account, []);

    expect($account->balances()->count())->toBe(0);
});

test('classifies an invalid or deleted query id as an auth error', function () {
    Http::fake([
        '*SendRequest*' => Http::response(
            ibFlexError('1020', 'Invalid request or unable to validate request.'),
        ),
    ]);

    $client = new InteractiveBrokersClient('token', 'deleted-query');

    try {
        $client->fetchStatement();
        $this->fail('Expected a RequestException');
    } catch (RequestException $e) {
        expect($e->response->status())->toBe(401);
    }
});

test('skips NAV rows that have no total', function () {
    [$user, $connection] = ibSetup();
    $account = ibAccount($user, $connection);

    $statement = '<FlexQueryResponse queryName="Whisper" type="AF"><FlexStatements count="1">'
        .'<FlexStatement accountId="U1234567"><AccountInformation accountId="U1234567" currency="USD" />'
        .'<EquitySummaryInBase>'
        .'<EquitySummaryByReportDateInBase reportDate="20250114" cash="0" />'
        .'<EquitySummaryByReportDateInBase reportDate="20250115" cash="0" total="10000.00" />'
        .'</EquitySummaryInBase></FlexStatement></FlexStatements></FlexQueryResponse>';

    Http::fake([
        '*SendRequest*' => Http::response(ibSendRequestXml()),
        '*GetStatement*' => Http::response($statement),
    ]);

    $accounts = (new InteractiveBrokersClient('token', '123456'))->fetchStatement();
    app(InteractiveBrokersBalanceSyncService::class)->sync($account, $accounts);

    expect($account->balances()->count())->toBe(1);
    expect($account->balances()->first()->balance_date->toDateString())->toBe('2025-01-15');
});
