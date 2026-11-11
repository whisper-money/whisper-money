<?php

use App\Services\Widgets\Gateway\ManifestValidator;
use Illuminate\Validation\ValidationException;

/**
 * The validation errors of $manifest, by field: empty when it is valid.
 *
 * @param  array<mixed>  $manifest
 * @return array<string, list<string>>
 */
function manifestErrors(array $manifest): array
{
    try {
        app(ManifestValidator::class)->validate($manifest);

        return [];
    } catch (ValidationException $exception) {
        return $exception->errors();
    }
}

/**
 * A valid manifest with $data as its calls.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function manifestWith(array $data, array $size = ['width' => '1/2', 'height' => 'm']): array
{
    return ['name' => 'Groceries by month', 'size' => $size, 'data' => $data];
}

it('accepts the plan\'s example manifest', function () {
    $manifest = manifestWith([
        'spending' => ['endpoint' => 'GET /analytics/spending-by-category', 'params' => ['from' => '{{range.from}}', 'to' => '{{range.to}}']],
        'groceries' => ['endpoint' => 'GET /transactions', 'params' => ['category_id' => '9b1f5c0e-0000-4000-8000-000000000000', 'from' => '{{range.from}}'], 'paginate' => 'all'],
        'balances' => ['endpoint' => 'GET /accounts/{account}/balances', 'params' => ['account' => '9b1f5c0e-0000-4000-8000-000000000001', 'limit' => 10]],
        'byMonth' => ['endpoint' => 'GET /analytics/aggregate', 'params' => ['from' => '2026-01-01', 'to' => '{{range.to}}', 'group_by' => 'month,category', 'include_subcategories' => false]],
    ], ['width' => '1/2', 'height' => 'm', 'allowed_widths' => ['1/2', '1/1'], 'allowed_heights' => ['m']]);

    expect(app(ManifestValidator::class)->validate($manifest))->toBe($manifest);
});

it('refuses an endpoint that is not registered', function () {
    expect(manifestErrors(manifestWith(['users' => ['endpoint' => 'GET /users']]))['data.users.endpoint'][0])
        ->toStartWith('GET /users is not available to widgets. Use one of: GET /spaces, GET /accounts,');
});

it('refuses any method but GET', function (string $endpoint) {
    expect(manifestErrors(manifestWith(['call' => ['endpoint' => $endpoint, 'params' => ['transaction' => '9b1f5c0e-0000-4000-8000-000000000000']]])))
        ->toBe(['data.call.endpoint' => ['Widgets only read data: '.strtok($endpoint, ' ').' is not allowed, only GET endpoints are.']]);
})->with(['POST /transactions', 'DELETE /transactions/{transaction}', 'PATCH /transactions/{transaction}', 'PUT /transactions/{transaction}/labels']);

it('refuses an endpoint that is not a method and a path', function () {
    expect(manifestErrors(manifestWith(['call' => ['endpoint' => '/transactions']])))
        ->toBe(['data.call.endpoint' => ['The endpoint "/transactions" must be a method and a path, such as "GET /transactions".']]);
});

it('refuses parameters the endpoint does not take', function () {
    expect(manifestErrors(manifestWith(['savings' => ['endpoint' => 'GET /analytics/monthly-savings', 'params' => ['from' => '2026-10-01']]])))
        ->toBe(['data.savings.params.from' => ['GET /analytics/monthly-savings has no parameter "from". It takes none.']])
        ->and(manifestErrors(manifestWith(['budgets' => ['endpoint' => 'GET /budgets', 'params' => ['space' => 'x']]]))['data.budgets.params.space'][0])
        ->toBe('GET /budgets has no parameter "space". It takes: limit, starting_after, ending_before.');
});

it('checks the type of each parameter', function () {
    expect(manifestErrors(manifestWith(['call' => ['endpoint' => 'GET /transactions', 'params' => [
        'from' => ['2026-10-01'],
        'label_ids' => [['nested']],
        'min_amount' => 100,
    ]]])))->toBe([
        'data.call.params.from' => ['The from parameter must be a string, a number or a boolean.'],
        'data.call.params.label_ids' => ['The label_ids parameter must be a list of strings or numbers, or a single string.'],
    ]);
});

it('only accepts the dashboard variables it knows', function () {
    expect(manifestErrors(manifestWith(['call' => ['endpoint' => 'GET /analytics/aggregate', 'params' => [
        'from' => '{{range.start}}',
        'to' => '{{range.to}}',
        'label_ids' => ['{{user.id}}'],
    ]]])))->toBe([
        'data.call.params.from' => ['{{range.start}} is not a dashboard variable. The variables are: {{range.from}}, {{range.to}}.'],
        'data.call.params.label_ids' => ['{{user.id}} is not a dashboard variable. The variables are: {{range.from}}, {{range.to}}.'],
    ]);
});

it('needs the ids in the endpoint\'s path', function () {
    expect(manifestErrors(manifestWith(['account' => ['endpoint' => 'GET /accounts/{account}']])))
        ->toBe(['data.account.params' => ['GET /accounts/{account} needs params.account, the id in its path.']]);
});

it('only reads a whole list from a list endpoint, without cursor parameters', function () {
    expect(manifestErrors(manifestWith([
        'cashflow' => ['endpoint' => 'GET /analytics/cashflow', 'params' => ['from' => '{{range.from}}', 'to' => '{{range.to}}'], 'paginate' => 'all'],
        'rows' => ['endpoint' => 'GET /transactions', 'params' => ['limit' => 50], 'paginate' => 'all'],
        'pages' => ['endpoint' => 'GET /transactions', 'paginate' => 'some'],
    ])))->toBe([
        'data.pages.paginate' => ['The only paginate value is "all", which reads the whole list.'],
    ])->and(manifestErrors(manifestWith([
        'cashflow' => ['endpoint' => 'GET /analytics/cashflow', 'params' => ['from' => '{{range.from}}', 'to' => '{{range.to}}'], 'paginate' => 'all'],
        'rows' => ['endpoint' => 'GET /transactions', 'params' => ['limit' => 50], 'paginate' => 'all'],
    ])))->toBe([
        'data.cashflow.paginate' => ['GET /analytics/cashflow is not a paginated list, so it takes no paginate.'],
        'data.rows.params.limit' => ['paginate "all" reads the whole list, so it takes no limit.'],
    ]);
});

it('names each call with a JavaScript identifier', function () {
    expect(manifestErrors(manifestWith(['by month' => ['endpoint' => 'GET /labels']])))
        ->toBe(['data.by month' => ['The data key "by month" must be a JavaScript identifier, such as "spending": the widget reads it from useWidgetData().']]);
});

it('refuses unknown keys in a call', function () {
    expect(manifestErrors(manifestWith(['labels' => ['endpoint' => 'GET /labels', 'method' => 'POST']])))
        ->toBe(['data.labels' => ['Each data call takes only endpoint, params and paginate.']]);
});

describe('name and size', function () {
    it('needs a name, a size and at least one call', function () {
        expect(array_keys(manifestErrors([])))->toBe(['name', 'size', 'size.width', 'size.height', 'data']);
    });

    it('takes only the fixed sizes', function () {
        expect(manifestErrors(manifestWith(['labels' => ['endpoint' => 'GET /labels']], ['width' => '1/4', 'height' => 'xl', 'allowed_widths' => ['2/3']])))->toBe([
            'size.width' => ['The size.width must be one of: 1/3, 1/2, 1/1.'],
            'size.height' => ['The size.height must be one of: m, l.'],
            'size.allowed_widths.0' => ['Every allowed width must be one of: 1/3, 1/2, 1/1.'],
        ]);
    });

    it('needs the allowed sizes to include its own', function () {
        expect(manifestErrors(manifestWith(['labels' => ['endpoint' => 'GET /labels']], ['width' => '1/3', 'height' => 'l', 'allowed_widths' => ['1/2', '1/1'], 'allowed_heights' => ['m']])))->toBe([
            'size.allowed_widths' => ["The size.allowed_widths must include the widget's own width, 1/3."],
            'size.allowed_heights' => ["The size.allowed_heights must include the widget's own height, l."],
        ]);
    });

    it('caps the number of calls', function () {
        $data = collect(range(1, 21))->mapWithKeys(fn (int $call): array => ["call{$call}" => ['endpoint' => 'GET /labels']])->all();

        expect(manifestErrors(manifestWith($data)))->toBe(['data' => ['A widget can declare at most 20 data calls.']]);
    });
});
