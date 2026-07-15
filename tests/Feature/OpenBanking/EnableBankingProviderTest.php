<?php

use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use App\Services\Banking\EnableBankingProvider;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

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
        $provider->getTransactions('ext-123', '2025-05-05', '2026-05-05', strategy: 'longest');
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

function enableBankingProviderForTest(): EnableBankingProvider
{
    $privateKey = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDWoizjYmPaLQqn
uGJQxJCl18MxlJmTgoDzITt/hIW2CEFegbuKuynz7HCFM7xdAg6WRmHfOevLXVuq
+erPk9gcqC1ePLWzwzmNLIIPpPrO4pkFTZF91T46kJY9/J6QclzbrbI4wB9l3SKA
14h0O2R2sh1DubnSN5H7JeHyZtIal+aJe7jxuLyKxKkWY80a/jq7rGIzQFJFCFFV
zLcRKyqs80l4nGLT00lubmlJj1y2/p0OH7B8ZLwxr2LrH+NAPw9L6/e8jEhHSxHs
LLgOeCEIHO3f7tAfWN6dld08I9puT5JtXp8c5OpkrciDD5C3HvOGjQFNj/W7EmRg
GVIBeDf7AgMBAAECggEAAJOXLJWl9T70krfCfztGFx3MNtmv/P8GF0OPFp/KnsU1
SoMenxzkb8OkyPYyMPxhi0PemEdAvlByTnk6EwxvgEoNDNa2rXb5gy1zUCPUWMrq
806Ur9AI3Muj7/s57LvJ6HMnalyb58BBvEbwjLNgmiEsRhrML8pA9hd4sGam/vq/
Xb1BoT8FRPVlmz32w9RFrcQaZ4tO/r8rRNlWFtEV0iOdocK+4NizJvJvCyPYesck
F8+wAoPrHARSOhmzWfzYXXFwJdXcpkuMshQ+COzD2TTZnTZbRn8tWMcL32Bb9b55
E1CKVPUB99eE182oCHaWNE7HO+2VbMFqExU9oZU+fQKBgQD8HjrFlP234WDChook
ED5btDxJqSpGuHvzgP083Ej8sOLtWcpVJOFEsLiKRzUqBm6wjFrfk8yq+Vk3OgoA
CDV6owfQGwn0Jj7yhYPDlMUf1mqytbeFSrziIaFs8YcV1nxykXbJCQyDIAhjOlXf
je9SifsrBDxOv6re2ky8mzzp1QKBgQDZ8DF64aEntI78SP6CW72fUrQkA9HOnV5s
dZLE/RbybTG/oozjJzJ0OTHiwtz14UVxTXTCEkF4nsrv9W19pw5E/C4wLqq7tdDn
gXxS0CAQ1zCBqQAMrgeMA+mmNc3j7rp/TthMQ+Z+wStOqptkIvigv6EZ/9+jzdSA
C5O5nq4yjwKBgEzhpwhze79kIg6P2nZO4cUzPCM2S+cPAPVrg03Y2wT7p+e7NuEq
AuvgfBXmywaKuZxq4JdHSeVlblhSAZSq7Cv+pTZH2Iw0UYPBRUISDt67kwP2OAWU
me7XVJOVP51gL8j8JN3/PWqLDSO9OUyXysA/xXEDtKRK/H9C0J2/NR8VAoGASr4B
ei8fYcqerw8pmfN0mMt4VFGrBr0ZwQChkUVrNUEVqq9Iui6bMxjabvZ9aSYU9sKl
pFk2cvOijaESJ+G/FxGVlZirnSzBtGPIC26tUJk8XXtkNPUKSY6d9w7EycL52udj
buRqjFYbUCNan4EO27JcwdnrDPZuRmuyAhrViykCgYEA4pLCByU4uISinHpFKWD4
TMGRZNdyFw1UWET/t3UgYA05iFzgrlaz5WtWy27LVHGIpDZqmR/pqw43tsOX67qi
r6aIG0QnM0a0BlAPUi+7BBZL76TatYBoYlqbvLOaRRaYsL4s4jGph+KUS4Sr/JmK
+Y9QVqKpHPmUKWPRdA7INQ0=
-----END PRIVATE KEY-----
PEM;

    $path = sys_get_temp_dir().'/enablebanking-test-key.pem';
    file_put_contents($path, $privateKey);

    return new EnableBankingProvider('test-app-id', $path);
}
