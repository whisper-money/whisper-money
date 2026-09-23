<?php

use App\Exceptions\Banking\TransientBankingProviderException;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\KrakenBalanceSyncService;
use App\Services\Banking\KrakenClient;
use App\Services\CurrencyConversionService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();

    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->connection = BankingConnection::factory()->kraken()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->connected()->create([
        'user_id' => $this->user->id,
        'banking_connection_id' => $this->connection->id,
        'external_account_id' => 'kraken-portfolio',
        'currency_code' => 'EUR',
    ]);
});

afterEach(function () {
    Sleep::fake(false);
});

/**
 * @param  array<string, array<string, mixed>>  $deposits
 * @param  array<string, array<string, mixed>>  $withdrawals
 */
function fakeKraken(array $balances, array $ticker = [], array $deposits = [], array $withdrawals = []): void
{
    Http::fake([
        'api.kraken.com/0/public/Ticker' => Http::response([
            'error' => [],
            'result' => array_map(fn (string $price) => ['c' => [$price, '1.0']], $ticker),
        ]),
        'api.kraken.com/0/private/Balance' => Http::response(['error' => [], 'result' => $balances]),
        'api.kraken.com/0/private/Ledgers' => function (Request $request) use ($deposits, $withdrawals) {
            $entries = $request['type'] === 'deposit' ? $deposits : $withdrawals;

            return Http::response(['error' => [], 'result' => ['ledger' => $entries, 'count' => count($entries)]]);
        },
    ]);
}

function ledgerEntry(string $asset, string $amount, float $time, string $type = 'deposit'): array
{
    return ['refid' => uniqid(), 'time' => $time, 'type' => $type, 'subtype' => '', 'asset' => $asset, 'amount' => $amount, 'fee' => '0'];
}

/**
 * Rates keyed "SOURCE:date" → value of one unit in EUR. Unknown pairs return 0,
 * like the real service.
 *
 * @param  array<string, float>  $rates
 */
function fakeRates(array $rates): void
{
    test()->mock(CurrencyConversionService::class)
        ->shouldReceive('convert')
        ->andReturnUsing(function (string $source, string $target, float $quantity, string $date) use ($rates) {
            if (strtoupper($source) === strtoupper($target)) {
                return $quantity;
            }

            return $quantity * ($rates["{$source}:{$date}"] ?? $rates[$source] ?? 0.0);
        });
}

function syncKraken(bool $isFirstSync = true): void
{
    $connection = test()->connection->refresh();

    app(KrakenBalanceSyncService::class)->sync(
        $connection,
        test()->account,
        new KrakenClient($connection->api_token, $connection->api_secret),
        $isFirstSync,
    );
}

test('collapses staked and legacy codes onto one asset priced with kraken pairs', function () {
    fakeKraken(
        balances: ['XXBT' => '0.5', 'XBT.M' => '0.5', 'DOT' => '10', 'DOT.S' => '10', 'ETH2.S' => '1', 'ZEUR' => '100', 'EUR.HOLD' => '50'],
        ticker: ['XXBTZEUR' => '50000', 'DOTEUR' => '5', 'XETHZEUR' => '2000'],
    );

    syncKraken();

    // 1 BTC * 50000 + 20 DOT * 5 + 1 ETH * 2000 + 150 EUR
    expect($this->account->balances()->sole()->balance)->toBe(5_225_000);
});

test('prices an asset without a pair in the account currency through its usd pair', function () {
    fakeKraken(balances: ['SOL' => '2'], ticker: ['SOLUSD' => '100']);
    fakeRates(['USD' => 0.5]);

    syncKraken();

    expect($this->account->balances()->sole()->balance)->toBe(10_000);
});

test('falls back to the rate provider under its own code for an asset kraken does not quote', function () {
    fakeKraken(balances: ['XXDG' => '100']);
    fakeRates(['DOGE' => 0.1]);

    syncKraken();

    expect($this->account->balances()->sole()->balance)->toBe(1_000);
});

test('records the balance even when the portfolio is empty', function () {
    fakeKraken(balances: []);

    syncKraken();

    expect($this->account->balances()->sole()->balance)->toBe(0);
});

test('counts fiat and crypto deposits minus withdrawals at their date as invested amount', function () {
    $monday = Carbon\Carbon::parse('2026-01-05 10:00:00', 'UTC');
    fakeKraken(
        balances: [],
        deposits: [
            'L1' => ledgerEntry('ZEUR', '1000.0', $monday->getTimestamp()),
            'L2' => ledgerEntry('XXBT', '0.1', $monday->copy()->addDay()->getTimestamp()),
        ],
        withdrawals: [
            'L3' => ledgerEntry('ZUSD', '-200.0', $monday->copy()->addDays(2)->getTimestamp() + 0.5, 'withdrawal'),
        ],
    );
    fakeRates(['BTC:2026-01-06' => 40000.0, 'USD:2026-01-07' => 0.9]);

    syncKraken();

    // 1000 + 0.1 * 40000 - 200 * 0.9
    expect($this->account->balances()->sole()->invested_amount)->toBe(482_000)
        ->and($this->connection->refresh()->ledger_synced_until->getTimestamp())->toBe($monday->copy()->addDays(2)->getTimestamp() + 1);
});

test('prices a deposit the rate provider cannot value on its date at todays kraken price', function () {
    fakeKraken(balances: [], ticker: ['PEPEEUR' => '0.001'], deposits: ['L1' => ledgerEntry('PEPE', '1000000', 1_700_000_000)]);
    fakeRates([]);

    syncKraken();

    expect($this->account->balances()->sole()->invested_amount)->toBe(100_000);
});

test('a staking move booked as withdrawal plus a staked deposit cancels out', function () {
    fakeKraken(
        balances: [],
        deposits: ['L1' => ledgerEntry('ZEUR', '500', 1_700_000_000), 'L2' => ledgerEntry('DOT.S', '10', 1_700_000_100)],
        withdrawals: ['L3' => ledgerEntry('DOT', '-10', 1_700_000_100, 'withdrawal')],
    );
    fakeRates(['DOT' => 5.0]);

    syncKraken();

    expect($this->account->balances()->sole()->invested_amount)->toBe(50_000);
});

test('an incremental sync only fetches entries after the cursor and adds them to the last invested amount', function () {
    $this->connection->update(['ledger_synced_until' => Carbon\Carbon::createFromTimestamp(1_700_000_000)]);
    $this->account->balances()->create(['balance_date' => now()->subDay()->toDateString(), 'balance' => 0, 'invested_amount' => 100_000]);

    fakeKraken(balances: [], deposits: ['L9' => ledgerEntry('ZEUR', '250', 1_700_000_500.2)]);

    syncKraken(isFirstSync: false);

    expect($this->account->balances()->whereDate('balance_date', now())->sole()->invested_amount)->toBe(125_000)
        ->and($this->connection->refresh()->ledger_synced_until->getTimestamp())->toBe(1_700_000_501);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'Ledgers') && $request['start'] === '1700000000');
});

test('an incremental sync with no new entries carries the last invested amount forward', function () {
    $this->connection->update(['ledger_synced_until' => Carbon\Carbon::createFromTimestamp(1_700_000_000)]);
    $this->account->balances()->create(['balance_date' => now()->subDay()->toDateString(), 'balance' => 0, 'invested_amount' => 100_000]);

    fakeKraken(balances: []);

    syncKraken(isFirstSync: false);

    expect($this->account->balances()->whereDate('balance_date', now())->sole()->invested_amount)->toBe(100_000)
        ->and($this->connection->refresh()->ledger_synced_until->getTimestamp())->toBe(1_700_000_000);
});

test('a forced full sync walks the whole ledger again', function () {
    $this->connection->update(['ledger_synced_until' => Carbon\Carbon::createFromTimestamp(1_700_000_000)]);
    $this->account->balances()->create(['balance_date' => now()->subDay()->toDateString(), 'balance' => 0, 'invested_amount' => 999_999]);

    fakeKraken(balances: [], deposits: ['L1' => ledgerEntry('ZEUR', '300', 1_600_000_000)]);

    syncKraken(isFirstSync: true);

    expect($this->account->balances()->whereDate('balance_date', now())->sole()->invested_amount)->toBe(30_000);
    Http::assertNotSent(fn (Request $request) => isset($request['start']));
});

test('walks every ledger page', function () {
    $page = fn (int $from, int $count) => collect(range($from, $from + $count - 1))
        ->mapWithKeys(fn (int $i) => ["L{$i}" => ledgerEntry('ZEUR', '1', 1_700_000_000 + $i)])
        ->all();

    Http::fake([
        'api.kraken.com/0/public/Ticker' => Http::response(['error' => [], 'result' => []]),
        'api.kraken.com/0/private/Balance' => Http::response(['error' => [], 'result' => []]),
        'api.kraken.com/0/private/Ledgers' => function (Request $request) use ($page) {
            if ($request['type'] === 'withdrawal') {
                return Http::response(['error' => [], 'result' => ['ledger' => [], 'count' => 0]]);
            }

            $rows = (int) $request['ofs'] === 0 ? $page(0, 50) : $page(50, 10);

            return Http::response(['error' => [], 'result' => ['ledger' => $rows, 'count' => 60]]);
        },
    ]);

    syncKraken();

    expect($this->account->balances()->sole()->invested_amount)->toBe(6_000);
});

test('signs private requests with kraken api-sign and a strictly increasing nonce', function () {
    fakeKraken(balances: []);
    $secret = base64_encode('super-secret');
    $client = new KrakenClient('the-key', $secret);

    $client->getBalance();
    $client->getBalance();

    $nonces = [];
    Http::assertSent(function (Request $request) use ($secret, &$nonces) {
        parse_str($request->body(), $fields);
        $nonces[] = (int) $fields['nonce'];
        $expected = base64_encode(hash_hmac('sha512', '/0/private/Balance'.hash('sha256', $fields['nonce'].$request->body(), true), base64_decode($secret), true));

        return $request->hasHeader('API-Key', 'the-key') && $request->header('API-Sign')[0] === $expected;
    });

    expect($nonces)->toHaveCount(2)->and($nonces[1])->toBeGreaterThan($nonces[0]);
});

test('kraken errors returned with a 200 map onto the statuses the sync job acts on', function (string $error, int $status) {
    Http::fake(['api.kraken.com/*' => Http::response(['error' => [$error]])]);

    try {
        (new KrakenClient('key', base64_encode('secret')))->getBalance();
        $this->fail('Expected a RequestException.');
    } catch (RequestException $e) {
        expect($e->response->status())->toBe($status)
            ->and($e->response->body())->toContain($error);
    }
})->with([
    ['EAPI:Invalid key', 401],
    ['EAPI:Invalid signature', 401],
    ['EGeneral:Permission denied', 401],
    ['EAPI:Rate limit exceeded', 429],
    ['EGeneral:Too many requests', 429],
]);

test('a kraken rate limit is retried with backoff', function () {
    Http::fake([
        'api.kraken.com/*' => Http::sequence()
            ->push(['error' => ['EAPI:Rate limit exceeded']])
            ->push(['error' => [], 'result' => ['ZEUR' => '1']]),
    ]);

    expect((new KrakenClient('key', base64_encode('secret')))->getBalance())->toBe(['ZEUR' => '1']);
    Http::assertSentCount(2);
});

test('a kraken service outage is transient', function (string $error) {
    Http::fake(['api.kraken.com/*' => Http::response(['error' => [$error]])]);

    expect(fn () => (new KrakenClient('key', base64_encode('secret')))->getBalance())
        ->toThrow(TransientBankingProviderException::class);
})->with(['EService:Unavailable', 'EService:Busy', 'EGeneral:Internal error']);
