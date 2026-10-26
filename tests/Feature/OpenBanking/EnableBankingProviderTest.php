<?php

use App\Exceptions\Banking\BankingRequestException;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The two account endpoints a sync calls. Both go through the same error
 * classification, so every failure below is checked against each of them.
 */
dataset('enable banking account endpoints', [
    'getTransactions' => ['transactions', fn ($provider) => $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString())],
    'getBalances' => ['balances', fn ($provider) => $provider->getBalances('ext-123')],
]);

test('known upstream failures are wrapped as non-reportable errors', function (string $path, Closure $call, Closure $response, string $exception, string $previous, array $properties) {
    Http::fake([
        "api.enablebanking.com/accounts/ext-123/{$path}*" => $response(),
    ]);

    try {
        $call(enableBankingProviderForTest());
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf($exception)
            ->toBeInstanceOf(ShouldntReport::class)
            ->and($e->getPrevious())->toBeInstanceOf($previous);

        foreach ($properties as $property => $value) {
            expect($e->{$property})->toBe($value);
        }

        return;
    }

    test()->fail("Expected {$exception}.");
})->with('enable banking account endpoints')->with([
    'an ASPSP error' => [
        fn () => Http::response([
            'code' => 400,
            'message' => 'Error interacting with ASPSP',
            'detail' => 'Unknown error',
            'error' => 'ASPSP_ERROR',
        ], 400),
        TransientBankingProviderException::class,
        RequestException::class,
        ['provider' => 'enablebanking', 'statusCode' => 400, 'providerCode' => 'ASPSP_ERROR'],
    ],
    'a connection failure' => [
        fn () => Http::failedConnection(),
        TransientBankingProviderException::class,
        ConnectionException::class,
        ['provider' => 'enablebanking', 'statusCode' => null, 'providerCode' => null],
    ],
    'an upstream 500' => [
        fn () => Http::response([
            'code' => 500,
            'message' => 'Internal server error',
        ], 500),
        TransientBankingProviderException::class,
        RequestException::class,
        ['provider' => 'enablebanking', 'statusCode' => 500],
    ],
    // Payload shape from PHP-LARAVEL-3J. Expiring a connection is a one-way
    // door, so a single one of these must leave it schedulable and self-healing.
    'a PSU-action-required 403, transient rather than expiring the connection' => [
        fn () => Http::response([
            'code' => 403,
            'message' => 'Internal error.',
            'detail' => [
                'message' => 'Internal error.',
                'error_name' => 'PsuActionRequiredException',
            ],
        ], 403),
        TransientBankingProviderException::class,
        RequestException::class,
        ['statusCode' => 403, 'providerCode' => 'PsuActionRequiredException'],
    ],
    'an expired session 401' => [
        fn () => Http::response([
            'code' => 401,
            'message' => 'Session is expired',
            'error' => 'EXPIRED_SESSION',
            'detail' => null,
        ], 401),
        ExpiredBankingSessionException::class,
        RequestException::class,
        [],
    ],
    'a closed session 401, needing a reconnect' => [
        fn () => Http::response([
            'code' => 401,
            'message' => 'Session is closed',
            'error' => 'CLOSED_SESSION',
            'detail' => null,
        ], 401),
        ExpiredBankingSessionException::class,
        RequestException::class,
        [],
    ],
    'an inaccessible account 400' => [
        fn () => Http::response([
            'code' => 400,
            'message' => 'Account not found',
            'detail' => [
                'message' => 'Account not found',
                'error_name' => 'AccountNotAccessibleException',
            ],
        ], 400),
        InaccessibleBankAccountException::class,
        RequestException::class,
        [],
    ],
]);

test('getTransactions wraps a 422 wrong-period as a non-reportable error', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 422,
            'message' => 'Wrong transactions period requested',
            'detail' => [
                'message' => 'Timestamp no en periodo de tiempo aceptable',
            ],
        ], 422),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getTransactions('ext-123', '2026-04-06', '2026-07-07');
    } catch (WrongTransactionsPeriodException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected wrong transactions period exception.');
});

test('getTransactions keeps unrelated 422 validation errors reportable', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 422,
            'message' => 'Invalid account identifier',
        ], 422),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getTransactions('ext-123', '2026-04-06', '2026-07-07'))
        ->toThrow(RequestException::class);
});

test('getTransactions reads an opaque bank connector failure on a year-wide window as a period refusal', function () {
    // Exactly what production answers for Renta 4, which sits behind Redsys:
    // the EXECUTION_DATE_INVALID that Redsys actually raised never reaches us,
    // so the width of the window we asked for is the only signal there is.
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 400,
            'message' => 'Error interacting with ASPSP',
            'detail' => 'Unknown error',
            'error' => 'ASPSP_ERROR',
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getTransactions('ext-123', '2025-07-07', '2026-07-07', strategy: 'longest'))
        ->toThrow(WrongTransactionsPeriodException::class);
});

test('getTransactions keeps a bank connector failure on a 90-day window transient', function () {
    // The first rung of the narrowing ladder, and the bound on the retry above:
    // a 90-day window is not wider than 90 days, so the same body classifies as
    // an ordinary connector failure and the ladder stops after one extra call.
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 400,
            'message' => 'Error interacting with ASPSP',
            'detail' => 'Unknown error',
            'error' => 'ASPSP_ERROR',
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getTransactions('ext-123', '2026-04-08', '2026-07-07'))
        ->toThrow(TransientBankingProviderException::class);
});

test('getBalances is unaffected by the wide-window rule', function () {
    // Only the transactions call carries a window, and Renta 4's balances have
    // been the one thing that works all along. Keep it that way.
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances' => Http::response([
            'code' => 400,
            'message' => 'Error interacting with ASPSP',
            'detail' => 'Unknown error',
            'error' => 'ASPSP_ERROR',
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getBalances('ext-123'))
        ->toThrow(TransientBankingProviderException::class);
});

test('getTransactions keeps non-ASPSP client errors reportable', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 400,
            'message' => 'Invalid date range',
            'error' => 'VALIDATION_ERROR',
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getTransactions('ext-123', 'bad-date', now()->toDateString()))
        ->toThrow(RequestException::class);
});

test('getBalances keeps non-ASPSP client errors reportable', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances' => Http::response([
            'code' => 400,
            'message' => 'Invalid account',
            'error' => 'VALIDATION_ERROR',
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getBalances('ext-123'))
        ->toThrow(RequestException::class);
});

test('a 403 the bank sends for another reason still surfaces as a request exception', function () {
    // Only the documented PSU-action code is classified; a bare 403 stays an
    // error we want to hear about.
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 403,
            'message' => 'Forbidden',
            'detail' => null,
        ], 403),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString()))
        ->toThrow(RequestException::class);
});

test('a 401 with an unrecognised code still surfaces as a request exception', function () {
    // Only the documented session codes mean "reconnect". Widening this to any
    // 401 would silently expire every connection during a credentials bug of
    // our own, and expiring is a one-way door.
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 401,
            'message' => 'Invalid token',
            'error' => 'INVALID_TOKEN',
            'detail' => null,
        ], 401),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString()))
        ->toThrow(RequestException::class);
});

test('a 403 whose detail is not an object is not mistaken for a known code', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 403,
            'message' => 'Forbidden',
            'detail' => 'Forbidden',
        ], 403),
    ]);

    $provider = enableBankingProviderForTest();

    expect(fn () => $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString()))
        ->toThrow(RequestException::class);
});

test('a rate limit names the endpoint it came from', function (string $operation, string $path) {
    Http::fake([
        "api.enablebanking.com/accounts/ext-123/{$path}*" => Http::response([
            'code' => 429,
            'message' => 'Too many requests',
        ], 429),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $operation === 'balances'
            ? $provider->getBalances('ext-123')
            : $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString());
    } catch (BankingRequestException $e) {
        // Still a RequestException, so the job's rate-limit handling is untouched.
        expect($e)->toBeInstanceOf(RequestException::class)
            ->and($e->operation)->toBe($operation)
            ->and($e->response->status())->toBe(429);

        return;
    }

    test()->fail('Expected a banking request exception naming the operation.');
})->with([
    ['balances', 'balances'],
    ['transactions', 'transactions'],
]);

test('a wrapped failure names the endpoint it came from', function (string $operation, string $path) {
    Http::fake([
        "api.enablebanking.com/accounts/ext-123/{$path}*" => Http::response([
            'code' => 400,
            'message' => 'Error interacting with ASPSP',
            'error' => 'ASPSP_ERROR',
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $operation === 'balances'
            ? $provider->getBalances('ext-123')
            : $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString());
    } catch (TransientBankingProviderException $e) {
        expect($e->operation)->toBe($operation);

        return;
    }

    test()->fail('Expected a transient banking provider exception naming the operation.');
})->with([
    ['balances', 'balances'],
    ['transactions', 'transactions'],
]);

test('the API error log keeps the endpoint shape but not the bank identifiers', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-secret-123/balances*' => Http::response([
            'code' => 429,
            'message' => 'Too many requests',
        ], 429),
    ]);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->context;
    });

    try {
        enableBankingProviderForTest()->getBalances('ext-secret-123');
    } catch (RequestException) {
        // Expected
    }

    $paths = array_column($logged, 'path');

    expect($paths)->toContain('/accounts/{id}/balances')
        ->and(json_encode($logged))->not->toContain('ext-secret-123');
});

test('a metered quota logs as a warning, an unexpected status as an error', function (int $status, string $level, string $notLevel) {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances*' => Http::response([
            'code' => $status,
            'message' => 'Nope',
        ], $status),
    ]);

    Log::spy();

    try {
        enableBankingProviderForTest()->getBalances('ext-123');
    } catch (Throwable) {
        // What it throws is the caller's business; this is about what it logs.
    }

    // Asserting the absence too, because the point of the change is that the
    // error-level entry stops being written - collecting levels and looking for
    // the one you want passes just as well when both are there.
    Log::shouldHaveReceived('log')->with($level, 'EnableBanking API error', Mockery::any())->once();
    Log::shouldNotHaveReceived('log', [$notLevel, 'EnableBanking API error', Mockery::any()]);
})->with([
    // A quota the bank meters, which the app answers with a backoff.
    [429, 'warning', 'error'],
    // Nothing recognises this one, so it stays an application error. Deliberately
    // not a 5xx: `isTransientServerError` and `WiseClient` both read a 5xx as
    // "not an app error", so pinning a level for it here would be taking a side
    // in a disagreement this change has no business settling.
    [404, 'error', 'warning'],
]);

test('a 422 the bank did not raise about the period stays an application error', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 422,
            'message' => 'Validation error',
            'detail' => ['error_name' => 'ValidationError'],
        ], 422),
    ]);

    Log::spy();

    try {
        enableBankingProviderForTest()->getTransactions('ext-123', 'not-a-date', 'not-a-date');
    } catch (Throwable) {
        // What it throws is the caller's business; this is about the level.
    }

    // `isWrongPeriod` narrows 422 to the window the bank refused, so that a
    // genuine validation error still surfaces. Downgrading every 422 in the
    // logging closure was quietly defeating that.
    Log::shouldHaveReceived('log')->with('error', 'EnableBanking API error', Mockery::any())->once();
});

test('getInstitutions marks only the curated banks as beta, ignoring the provider flag', function () {
    Http::fake([
        'api.enablebanking.com/aspsps*' => Http::response([
            'aspsps' => [
                ['name' => 'MyInvestor Banco', 'country' => 'ES', 'beta' => false],
                ['name' => 'Openbank', 'country' => 'ES', 'beta' => true],
                ['name' => 'Trade Republic', 'country' => 'ES'],
                // Curated for FR only, so ES stays stable despite the provider flag.
                ['name' => 'American Express', 'country' => 'ES', 'beta' => true],
            ],
        ]),
    ]);

    $institutions = collect(enableBankingProviderForTest()->getInstitutions('ES'))
        ->keyBy('name');

    expect($institutions['MyInvestor Banco']['beta'])->toBeTrue()
        ->and($institutions['Trade Republic']['beta'])->toBeTrue()
        ->and($institutions['Openbank']['beta'])->toBeFalse()
        ->and($institutions['American Express']['beta'])->toBeFalse();
});

test('startAuthorization upgrades the authorization url the provider hands back to https', function () {
    Http::fake([
        'api.enablebanking.com/auth' => Http::response([
            'url' => 'http://tilisy.enablebanking.com/ais/start?sessionid=auth-1',
            'authorization_id' => 'auth-1',
        ]),
    ]);

    $result = enableBankingProviderForTest()
        ->startAuthorization('CaixaBank', 'ES', 'https://example.com/callback', 'state-1');

    // Port 80 on that host never answers, so the browser navigation dies where
    // the user cannot see it. The HSTS header that would upgrade the scheme is
    // only served over HTTPS, so a browser reaching the host for the first time
    // has nothing cached to upgrade with.
    expect($result['url'])->toBe('https://tilisy.enablebanking.com/ais/start?sessionid=auth-1')
        ->and($result['authorization_id'])->toBe('auth-1');
});

test('startAuthorization leaves an https authorization url untouched', function () {
    Http::fake([
        'api.enablebanking.com/auth' => Http::response([
            // A query param carrying its own http:// url: a bare str_replace
            // would corrupt it, so the rewrite is anchored to the scheme.
            'url' => 'https://tilisy.enablebanking.com/ais/start?redirect=http://localhost/callback',
            'authorization_id' => 'auth-2',
        ]),
    ]);

    $result = enableBankingProviderForTest()
        ->startAuthorization('CaixaBank', 'ES', 'https://example.com/callback', 'state-2');

    expect($result['url'])->toBe('https://tilisy.enablebanking.com/ais/start?redirect=http://localhost/callback');
});
