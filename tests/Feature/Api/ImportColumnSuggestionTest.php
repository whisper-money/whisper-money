<?php

use App\Exceptions\Ai\ImportMappingUnavailableException;
use App\Models\Account;
use App\Models\User;
use App\Services\Ai\JevClient;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

use function Pest\Laravel\actingAs;

/**
 * @param  array<string, array{0: string, 1: float}>  $answers  choice and confidence per field
 * @return array<string, mixed>
 */
function jevColumnAnswers(array $answers): array
{
    return [
        'model' => 'jev-latest',
        'answers' => array_map(fn (array $answer): array => [
            'choice' => $answer[0],
            'confidence' => $answer[1],
        ], $answers),
    ];
}

/**
 * @return list<array{header: string, shape: string}>
 */
function bankExportColumns(): array
{
    return [
        ['header' => 'Fecha operación', 'shape' => 'date (dd/mm/yyyy)'],
        ['header' => 'Concepto', 'shape' => 'text, avg 24 chars, distinct values'],
        ['header' => 'Importe', 'shape' => 'number, 2 decimals, mixed signs'],
        ['header' => 'Saldo', 'shape' => 'number, 2 decimals, all positive'],
    ];
}

beforeEach(function () {
    Sleep::fake();
    config()->set('ai_import_mapping.enabled', true);
    config()->set('ai_import_mapping.free_users_enabled', true);
    config()->set('ai_import_mapping.min_confidence', 0.5);
    config()->set('services.typesafe.key', 'test-key');
    config()->set('services.typesafe.enabled', true);

    $this->user = User::factory()->create();
    actingAs($this->user);
});

it('maps each transaction field to the header jev picked', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers([
        'transaction_date' => ['0', 0.97],
        'description' => ['1', 0.9],
        'amount' => ['2', 0.95],
        'currency' => ['none', 0.92],
        'balance' => ['3', 0.88],
        'creditor_name' => ['none', 0.8],
        'debtor_name' => ['1', 0.3],
        'transaction_type' => ['none', 0.9],
    ]))]);

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'transaction',
        'columns' => bankExportColumns(),
    ])->assertOk()->assertExactJson(['data' => [
        'transaction_date' => ['column' => 'Fecha operación', 'index' => 0, 'confidence' => 0.97],
        'description' => ['column' => 'Concepto', 'index' => 1, 'confidence' => 0.9],
        'amount' => ['column' => 'Importe', 'index' => 2, 'confidence' => 0.95],
        'currency' => ['column' => null, 'index' => null, 'confidence' => 0.92],
        'balance' => ['column' => 'Saldo', 'index' => 3, 'confidence' => 0.88],
        'creditor_name' => ['column' => null, 'index' => null, 'confidence' => 0.8],
        'debtor_name' => ['column' => null, 'index' => null, 'confidence' => 0.3],
        'transaction_type' => ['column' => null, 'index' => null, 'confidence' => 0.9],
    ], 'cached' => false]);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $amount = $request['questions']['amount'];

        return $request->url() === 'https://api.typesafe.ai/v1/systemone'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'jev-latest'
            && $request['state'] === ['columns' => [
                ['index' => 0, 'header' => 'Fecha operación', 'shape' => 'date (dd/mm/yyyy)'],
                ['index' => 1, 'header' => 'Concepto', 'shape' => 'text, avg 24 chars, distinct values'],
                ['index' => 2, 'header' => 'Importe', 'shape' => 'number, 2 decimals, mixed signs'],
                ['index' => 3, 'header' => 'Saldo', 'shape' => 'number, 2 decimals, all positive'],
            ]]
            && array_keys($request['questions']) === ['transaction_date', 'description', 'amount', 'currency', 'balance', 'creditor_name', 'debtor_name', 'transaction_type']
            && $amount['type'] === 'choice'
            && $amount['criteria'] === ['0' => 'Fecha operación', '1' => 'Concepto', '2' => 'Importe', '3' => 'Saldo', 'none' => 'No column holds this'];
    });
});

it('asks for the balance fields on a balance import', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers([
        'balance_date' => ['0', 0.9],
        'balance' => ['1', 0.9],
        'invested_amount' => ['none', 0.9],
    ]))]);

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'balance',
        'columns' => [
            ['header' => 'Date', 'shape' => 'date (yyyy-mm-dd)'],
            ['header' => 'Value', 'shape' => 'number, 2 decimals, all positive'],
        ],
    ])->assertOk()->assertExactJson(['data' => [
        'balance_date' => ['column' => 'Date', 'index' => 0, 'confidence' => 0.9],
        'balance' => ['column' => 'Value', 'index' => 1, 'confidence' => 0.9],
        'invested_amount' => ['column' => null, 'index' => null, 'confidence' => 0.9],
    ], 'cached' => false]);

    Http::assertSent(fn (Request $request): bool => array_keys($request['questions']) === ['balance_date', 'balance', 'invested_amount']);
});

it('leaves a field unmapped when jev skips it or picks a column that does not exist', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers([
        'balance_date' => ['7', 0.99],
        'balance' => ['none', 0.8],
    ]))]);

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'balance',
        'columns' => [['header' => 'Date', 'shape' => 'date (yyyy-mm-dd)']],
    ])->assertOk()->assertExactJson(['data' => [
        'balance_date' => ['column' => null, 'index' => null, 'confidence' => 0.99],
        'balance' => ['column' => null, 'index' => null, 'confidence' => 0.8],
        'invested_amount' => ['column' => null, 'index' => null, 'confidence' => 0],
    ], 'cached' => false]);
});

it('falls back once jev keeps failing transiently, and reports it', function (Closure $failure) {
    Exceptions::fake();
    Http::fakeSequence('api.typesafe.ai/*')
        ->pushResponse($failure())
        ->pushResponse($failure());

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'transaction',
        'columns' => bankExportColumns(),
    ])->assertOk()->assertExactJson(['data' => null, 'cached' => false]);

    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    Exceptions::assertReported(ImportMappingUnavailableException::class);
})->with([
    'rate limited' => fn () => fn () => Http::response([], 429),
    'server error' => fn () => fn () => Http::response([], 503),
    'unreachable' => fn () => fn () => Http::failedConnection(),
]);

it('tells repeated headers apart by position', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers(['transaction_date' => ['1', 0.9]]))]);

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'transaction',
        'columns' => [
            ['header' => 'Fecha', 'shape' => 'date (dd/mm/yyyy), value date'],
            ['header' => 'Fecha', 'shape' => 'date (dd/mm/yyyy)'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.transaction_date.column', 'Fecha')
        ->assertJsonPath('data.transaction_date.index', 1);
});

it('retries a transient failure in place before answering', function () {
    Http::fakeSequence('api.typesafe.ai/*')
        ->push([], 429)
        ->push(jevColumnAnswers(['balance_date' => ['0', 0.9]]));

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'balance',
        'columns' => [['header' => 'Date', 'shape' => 'date (yyyy-mm-dd)']],
    ])->assertOk()->assertJsonPath('data.balance_date.column', 'Date');

    Http::assertSentCount(2);
});

it('reports a rejected request and falls back', function () {
    Exceptions::fake();
    Http::fake(['api.typesafe.ai/*' => Http::response(['error' => 'invalid key'], 401)]);

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'transaction',
        'columns' => bankExportColumns(),
    ])->assertOk()->assertExactJson(['data' => null, 'cached' => false]);

    Http::assertSentCount(1);
    Exceptions::assertReported(fn (RequestException $e): bool => $e->response->status() === 401);
});

it('refuses anything beyond headers and shapes', function (array $payload, string $error) {
    Http::fake();

    $this->postJson(route('api.import.column-suggestions'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    Http::assertNothingSent();
})->with([
    'an unknown kind' => [['kind' => 'loan', 'columns' => [['header' => 'A', 'shape' => 'text']]], 'kind'],
    'no columns' => [['kind' => 'transaction', 'columns' => []], 'columns'],
    'too many columns' => [['kind' => 'transaction', 'columns' => array_fill(0, 61, ['header' => 'A', 'shape' => 'text'])], 'columns'],
    'columns keyed by name' => [['kind' => 'transaction', 'columns' => ['a' => ['header' => 'A', 'shape' => 'text']]], 'columns'],
    'a sample value in a column' => [['kind' => 'transaction', 'columns' => [['header' => 'A', 'shape' => 'text', 'sample' => 'Mercadona']]], 'columns.0'],
    'a column without a shape' => [['kind' => 'transaction', 'columns' => [['header' => 'A']]], 'columns.0.shape'],
    'an oversized header' => [['kind' => 'transaction', 'columns' => [['header' => str_repeat('a', 121), 'shape' => 'text']]], 'columns.0.header'],
    'an oversized shape' => [['kind' => 'transaction', 'columns' => [['header' => 'A', 'shape' => str_repeat('a', 121)]]], 'columns.0.shape'],
    'a non-string header' => [['kind' => 'transaction', 'columns' => [['header' => ['x'], 'shape' => 'text']]], 'columns.0.header'],
    'an extra top-level field' => [['kind' => 'transaction', 'columns' => [['header' => 'A', 'shape' => 'text']], 'rows' => [['Mercadona', '-12.40']]], 'rows'],
]);

it('labels an unnamed column by its shape and suggests it by position only', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers(['balance_date' => ['0', 0.9]]))]);

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'balance',
        'columns' => [['header' => '', 'shape' => 'date (yyyy-mm-dd)']],
    ])->assertOk()
        ->assertJsonPath('data.balance_date.column', null)
        ->assertJsonPath('data.balance_date.index', 0);

    Http::assertSent(fn (Request $request): bool => $request['questions']['balance_date']['criteria']['0'] === '(unnamed column, date (yyyy-mm-dd))');
});

it('forbids the endpoint when the gate denies the user', function () {
    config()->set('ai_import_mapping.enabled', false);
    Http::fake();

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'transaction',
        'columns' => bankExportColumns(),
    ])->assertForbidden();

    Http::assertNothingSent();
});

it('requires authentication', function () {
    auth()->logout();

    $this->postJson(route('api.import.column-suggestions'), [
        'kind' => 'transaction',
        'columns' => bankExportColumns(),
    ])->assertUnauthorized();
});

it('throttles at twenty requests a minute', function () {
    Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers([]))]);
    $payload = ['kind' => 'balance', 'columns' => [['header' => 'Date', 'shape' => 'date (yyyy-mm-dd)']]];

    foreach (range(1, 20) as $attempt) {
        $this->postJson(route('api.import.column-suggestions'), $payload)->assertOk();
    }

    $this->postJson(route('api.import.column-suggestions'), $payload)->assertTooManyRequests();
});

describe('cache', function () {
    beforeEach(function () {
        Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers(['transaction_date' => ['0', 0.9]]))]);
        $this->payload = ['kind' => 'transaction', 'columns' => bankExportColumns()];
    });

    it('serves a repeated layout from the cache without calling jev', function () {
        $this->postJson(route('api.import.column-suggestions'), $this->payload)
            ->assertOk()
            ->assertJsonPath('cached', false)
            ->assertJsonPath('data.transaction_date.column', 'Fecha operación');

        $this->postJson(route('api.import.column-suggestions'), $this->payload)
            ->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('data.transaction_date.column', 'Fecha operación');

        Http::assertSentCount(1);
    });

    it('asks again once the cache expires', function () {
        config()->set('ai_import_mapping.cache_ttl_days', 30);

        $this->postJson(route('api.import.column-suggestions'), $this->payload)->assertOk();
        $this->travel(31)->days();
        $this->postJson(route('api.import.column-suggestions'), $this->payload)->assertJsonPath('cached', false);

        Http::assertSentCount(2);
    });

    it('keys the cache by layout, kind, account and user', function (Closure $vary) {
        $this->postJson(route('api.import.column-suggestions'), $this->payload)->assertOk();

        $this->postJson(route('api.import.column-suggestions'), $vary->call($this, $this->payload))
            ->assertOk()
            ->assertJsonPath('cached', false);

        Http::assertSentCount(2);
    })->with([
        'reordered columns' => fn (array $payload): array => [...$payload, 'columns' => array_reverse($payload['columns'])],
        'a different shape' => function (array $payload): array {
            $payload['columns'][2]['shape'] = 'number, 2 decimals, all positive';

            return $payload;
        },
        'another kind' => fn (array $payload): array => [...$payload, 'kind' => 'balance'],
        'an account' => function (array $payload): array {
            return [...$payload, 'account_id' => Account::factory()->create(['user_id' => $this->user->id])->id];
        },
        'another user' => function (array $payload): array {
            actingAs(User::factory()->create());

            return $payload;
        },
    ]);
});

it('never caches a failed answer', function () {
    Http::fakeSequence('api.typesafe.ai/*')
        ->push([], 503)->push([], 503)->push([], 503)
        ->push(jevColumnAnswers(['transaction_date' => ['0', 0.9]]));

    $this->postJson(route('api.import.column-suggestions'), ['kind' => 'transaction', 'columns' => bankExportColumns()])->assertExactJson(['data' => null, 'cached' => false]);
    $this->postJson(route('api.import.column-suggestions'), ['kind' => 'transaction', 'columns' => bankExportColumns()])
        ->assertJsonPath('cached', false)
        ->assertJsonPath('data.transaction_date.column', 'Fecha operación');

    Http::assertSentCount(4);
});

describe('account', function () {
    beforeEach(function () {
        Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers([]))]);
    });

    it('accepts an account of the user', function () {
        $account = Account::factory()->create(['user_id' => $this->user->id]);

        $this->postJson(route('api.import.column-suggestions'), [
            'kind' => 'transaction',
            'columns' => bankExportColumns(),
            'account_id' => $account->id,
        ])->assertOk();
    });

    it('forbids an account of another user', function () {
        $account = Account::factory()->create();

        $this->postJson(route('api.import.column-suggestions'), [
            'kind' => 'transaction',
            'columns' => bankExportColumns(),
            'account_id' => $account->id,
        ])->assertForbidden();

        Http::assertNothingSent();
    });

    it('rejects an unknown or malformed account', function (string $accountId) {
        $this->postJson(route('api.import.column-suggestions'), [
            'kind' => 'transaction',
            'columns' => bankExportColumns(),
            'account_id' => $accountId,
        ])->assertUnprocessable()->assertJsonValidationErrors('account_id');
    })->with(['not-a-uuid', '01999999-9999-7999-8999-999999999999']);
});

describe('attempts and timeout', function () {
    it('gives the user-facing request two short attempts by default', function () {
        $this->mock(JevClient::class, function ($mock): void {
            $mock->shouldReceive('model')->andReturn('jev-latest');
            $mock->shouldReceive('send')->once()->withArgs(fn (array $body, int $timeout, int $attempts): bool => $timeout === 5 && $attempts === 2)->andReturnNull();
        });

        $this->postJson(route('api.import.column-suggestions'), [
            'kind' => 'transaction',
            'columns' => bankExportColumns(),
        ])->assertOk()->assertJsonPath('data', null);
    });

    it('takes its attempts from the config', function () {
        config()->set('ai_import_mapping.attempts', 3);
        Exceptions::fake();
        Http::fake(['api.typesafe.ai/*' => Http::response([], 503)]);

        $this->postJson(route('api.import.column-suggestions'), [
            'kind' => 'transaction',
            'columns' => bankExportColumns(),
        ])->assertOk();

        Http::assertSentCount(3);
    });

    it('sets the configured timeout on each attempt', function () {
        $options = app(JevClient::class)->request(Http::getFacadeRoot(), 5)->getOptions();

        expect($options['timeout'])->toBe(5)
            ->and($options['connect_timeout'])->toBe(5);
    });
});

describe('failure reports', function () {
    it('reports a run of failed answers once per window, not once per file', function (int $status, array $body) {
        config()->set('ai_import_mapping.report_every_minutes', 15);
        Exceptions::fake();
        Http::fake(['api.typesafe.ai/*' => Http::response($body, $status)]);
        $payload = ['kind' => 'transaction', 'columns' => bankExportColumns()];

        $this->postJson(route('api.import.column-suggestions'), $payload)->assertOk();
        $this->postJson(route('api.import.column-suggestions'), $payload)->assertOk();

        Exceptions::assertReportedCount(1);

        $this->travel(16)->minutes();
        $this->postJson(route('api.import.column-suggestions'), $payload)->assertOk();

        Exceptions::assertReportedCount(2);
    })->with([
        'exhausted attempts' => [503, []],
        'a rejected request' => [401, ['error' => 'invalid key']],
    ]);
});

describe('an unreadable answer', function () {
    it('is neither returned nor cached, and is reported once', function (array $body) {
        Exceptions::fake();
        Http::fake(['api.typesafe.ai/*' => Http::response($body)]);
        $payload = ['kind' => 'balance', 'columns' => [['header' => 'Date', 'shape' => 'date (yyyy-mm-dd)']]];

        $this->postJson(route('api.import.column-suggestions'), $payload)
            ->assertOk()
            ->assertExactJson(['data' => null, 'cached' => false]);
        $this->postJson(route('api.import.column-suggestions'), $payload)
            ->assertExactJson(['data' => null, 'cached' => false]);

        Http::assertSentCount(2);
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(ImportMappingUnavailableException::class);
    })->with([
        'no answers' => [['model' => 'jev-latest']],
        'answers not an object' => [['answers' => 'oops']],
        'no readable field' => [['answers' => [
            'balance_date' => ['choice' => '9', 'confidence' => 0.9],
            'balance' => ['choice' => 'banana', 'confidence' => 0.9],
            'invested_amount' => ['choice' => 'none'],
        ]]],
    ]);

    it('is not an all-none answer, which is well-formed and cached', function () {
        Exceptions::fake();
        Http::fake(['api.typesafe.ai/*' => Http::response(jevColumnAnswers([
            'balance_date' => ['none', 0.9],
            'balance' => ['none', 0.9],
            'invested_amount' => ['none', 0.9],
        ]))]);
        $payload = ['kind' => 'balance', 'columns' => [['header' => 'Notes', 'shape' => 'text, avg 12 chars, mostly distinct values']]];

        $this->postJson(route('api.import.column-suggestions'), $payload)->assertJsonPath('cached', false);
        $this->postJson(route('api.import.column-suggestions'), $payload)
            ->assertJsonPath('cached', true)
            ->assertJsonPath('data.balance_date.column', null);

        Http::assertSentCount(1);
        Exceptions::assertNothingReported();
    });
});
