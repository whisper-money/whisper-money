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

test('getTransactions wraps EnableBanking ASPSP errors as non-reportable transient errors', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 400,
            'message' => 'Error interacting with ASPSP',
            'detail' => 'Unknown error',
            'error' => 'ASPSP_ERROR',
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getTransactions('ext-123', '2026-04-08', '2026-07-07');
    } catch (TransientBankingProviderException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->provider)->toBe('enablebanking')
            ->and($e->statusCode)->toBe(400)
            ->and($e->providerCode)->toBe('ASPSP_ERROR')
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected transient banking provider exception.');
});

test('getTransactions wraps connection failures as non-reportable transient errors', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::failedConnection(),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString());
    } catch (TransientBankingProviderException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->provider)->toBe('enablebanking')
            ->and($e->statusCode)->toBeNull()
            ->and($e->providerCode)->toBeNull()
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);

        return;
    }

    test()->fail('Expected transient banking provider exception.');
});

test('getTransactions wraps an upstream 500 as a non-reportable transient error', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 500,
            'message' => 'Internal server error',
        ], 500),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString());
    } catch (TransientBankingProviderException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->provider)->toBe('enablebanking')
            ->and($e->statusCode)->toBe(500)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected transient banking provider exception.');
});

test('getBalances wraps an upstream 500 as a non-reportable transient error', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances*' => Http::response([
            'code' => 500,
            'message' => 'Internal server error',
        ], 500),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getBalances('ext-123');
    } catch (TransientBankingProviderException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->provider)->toBe('enablebanking')
            ->and($e->statusCode)->toBe(500)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected transient banking provider exception.');
});

test('getTransactions wraps an expired session 401 as a non-reportable expired session error', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 401,
            'message' => 'Session is expired',
            'error' => 'EXPIRED_SESSION',
            'detail' => null,
        ], 401),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString());
    } catch (ExpiredBankingSessionException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected expired banking session exception.');
});

test('getBalances wraps an expired session 401 as a non-reportable expired session error', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances' => Http::response([
            'code' => 401,
            'message' => 'Session is expired',
            'error' => 'EXPIRED_SESSION',
            'detail' => null,
        ], 401),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getBalances('ext-123');
    } catch (ExpiredBankingSessionException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected expired banking session exception.');
});

test('getTransactions wraps an inaccessible account 400 as a non-reportable error', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 400,
            'message' => 'Account not found',
            'detail' => [
                'message' => 'Account not found',
                'error_name' => 'AccountNotAccessibleException',
            ],
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString());
    } catch (InaccessibleBankAccountException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected inaccessible bank account exception.');
});

test('getBalances wraps an inaccessible account 400 as a non-reportable error', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances' => Http::response([
            'code' => 400,
            'message' => 'Account not found',
            'detail' => [
                'message' => 'Account not found',
                'error_name' => 'AccountNotAccessibleException',
            ],
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getBalances('ext-123');
    } catch (InaccessibleBankAccountException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected inaccessible bank account exception.');
});

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

test('getBalances wraps connection failures as non-reportable transient errors', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances' => Http::failedConnection(),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getBalances('ext-123');
    } catch (TransientBankingProviderException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->provider)->toBe('enablebanking')
            ->and($e->statusCode)->toBeNull()
            ->and($e->providerCode)->toBeNull()
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);

        return;
    }

    test()->fail('Expected transient banking provider exception.');
});

test('getBalances wraps EnableBanking ASPSP errors as non-reportable transient errors', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances' => Http::response([
            'code' => 400,
            'message' => 'Error interacting with ASPSP',
            'error' => 'ASPSP_ERROR',
        ], 400),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getBalances('ext-123');
    } catch (TransientBankingProviderException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->provider)->toBe('enablebanking')
            ->and($e->statusCode)->toBe(400)
            ->and($e->providerCode)->toBe('ASPSP_ERROR')
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected transient banking provider exception.');
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

test('getTransactions treats a PSU-action-required 403 as transient rather than expiring the connection', function () {
    // Payload shape from PHP-LARAVEL-3J. Expiring a connection is a one-way
    // door, so a single one of these must leave it schedulable and self-healing.
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 403,
            'message' => 'Internal error.',
            'detail' => [
                'message' => 'Internal error.',
                'error_name' => 'PsuActionRequiredException',
            ],
        ], 403),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString());
    } catch (TransientBankingProviderException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->statusCode)->toBe(403)
            ->and($e->providerCode)->toBe('PsuActionRequiredException')
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected transient banking provider exception.');
});

test('getTransactions treats a closed session 401 as needing a reconnect', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/transactions*' => Http::response([
            'code' => 401,
            'message' => 'Session is closed',
            'error' => 'CLOSED_SESSION',
            'detail' => null,
        ], 401),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getTransactions('ext-123', now()->toDateString(), now()->toDateString());
    } catch (ExpiredBankingSessionException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected expired banking session exception.');
});

test('getBalances treats a PSU-action-required 403 as transient rather than expiring the connection', function () {
    Http::fake([
        'api.enablebanking.com/accounts/ext-123/balances' => Http::response([
            'code' => 403,
            'message' => 'Internal error.',
            'detail' => [
                'message' => 'Internal error.',
                'error_name' => 'PsuActionRequiredException',
            ],
        ], 403),
    ]);

    $provider = enableBankingProviderForTest();

    try {
        $provider->getBalances('ext-123');
    } catch (TransientBankingProviderException $e) {
        expect($e)->toBeInstanceOf(ShouldntReport::class)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);

        return;
    }

    test()->fail('Expected transient banking provider exception.');
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

test('getInstitutions passes the provider beta flag through, defaulting to stable', function () {
    Http::fake([
        'api.enablebanking.com/aspsps*' => Http::response([
            'aspsps' => [
                ['name' => 'Openbank', 'country' => 'ES', 'beta' => true],
                ['name' => 'BBVA', 'country' => 'ES', 'beta' => false],
                ['name' => 'CaixaBank', 'country' => 'ES'],
            ],
        ]),
    ]);

    $institutions = collect(enableBankingProviderForTest()->getInstitutions('ES'))
        ->keyBy('name');

    expect($institutions['Openbank']['beta'])->toBeTrue()
        ->and($institutions['BBVA']['beta'])->toBeFalse()
        // A connector the provider says nothing about is not a beta connector.
        ->and($institutions['CaixaBank']['beta'])->toBeFalse();
});
