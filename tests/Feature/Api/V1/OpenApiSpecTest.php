<?php

use App\Models\ApiRequest;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\ApiV1\DocumentedFixtureController;

/**
 * @return array<string, mixed>
 */
function generatedSpec(): array
{
    return app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
}

it('serves the spec without a token, outside the api-v1 middleware', function () {
    $response = $this->getJson('/api/v1/openapi.json')
        ->assertOk()
        ->assertJsonPath('info.title', 'Whisper Money API')
        ->assertJsonPath('info.version', 'v1');

    expect(json_decode((string) $response->getContent(), true))->toBeArray()
        ->and($response->json('paths'))->toHaveKey('/spaces')
        ->and($response->json('paths'))->not->toHaveKey('/openapi.json')
        ->and(ApiRequest::query()->count())->toBe(0);
});

it('allows any origin to fetch the spec', function () {
    $this->getJson('/api/v1/openapi.json', ['Origin' => 'https://example.com'])
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('pins the server URL to the app URL, whatever host the request claims', function () {
    $this->getJson('/api/v1/openapi.json', ['X-Forwarded-Host' => 'evil.test'])
        ->assertOk()
        ->assertJsonPath('servers.0.url', rtrim((string) config('app.url'), '/').'/api/v1');
});

it('does not register the Scramble UI or its own spec route', function () {
    $this->get('/docs/api')->assertNotFound();
    $this->get('/docs/api.json')->assertNotFound();
});

it('documents both ways to authenticate', function () {
    $spec = generatedSpec();

    expect($spec['security'])->toBe([['personalAccessToken' => []], ['oauth2' => ['api:use']]])
        ->and($spec['components']['securitySchemes']['personalAccessToken'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
        ->and($spec['components']['securitySchemes']['oauth2']['flows']['authorizationCode'])->toMatchArray([
            'authorizationUrl' => route('passport.authorizations.authorize'),
            'tokenUrl' => route('passport.token'),
            'scopes' => ['api:use' => 'Use the Whisper Money API'],
        ]);
});

it('documents the scope, errors and rate-limit headers of every endpoint', function () {
    $operation = generatedSpec()['paths']['/spaces']['get'];

    expect($operation['x-token-ability'])->toBe('api:read')
        ->and($operation['tags'])->toBe(['Spaces'])
        ->and(array_keys($operation['responses']))->toBe([200, 401, 403, 429])
        ->and($operation['responses'][401])->toBe(['$ref' => '#/components/responses/Unauthenticated'])
        ->and(array_keys($operation['responses'][200]['headers']))->toBe(['X-RateLimit-Limit', 'X-RateLimit-Remaining'])
        ->and($operation['responses'][200]['content']['application/json']['schema']['properties']['data']['items'])
        ->toBe(['$ref' => '#/components/schemas/Space']);
});

describe('conventions for later endpoints', function () {
    beforeEach(function () {
        Route::middleware('api-v1')->prefix('api/v1')->group(function () {
            Route::get('fixtures', [DocumentedFixtureController::class, 'index']);
            Route::post('fixtures', [DocumentedFixtureController::class, 'store']);
            Route::delete('fixtures/{fixture}', [DocumentedFixtureController::class, 'destroy']);
        });
    });

    it('documents cursor pagination and the space parameter', function () {
        $operation = generatedSpec()['paths']['/fixtures']['get'];
        $parameters = collect($operation['parameters'])->keyBy('name');

        expect($parameters->keys()->all())->toContain('limit', 'starting_after', 'ending_before', 'space')
            ->and($parameters['space']['in'])->toBe('query')
            ->and(array_keys($operation['responses']))->toBe([200, 400, 401, 403, 422, 429])
            ->and($operation['responses'][200]['content']['application/json']['schema'])->toMatchArray([
                'type' => 'object',
                'required' => ['data', 'has_more'],
            ])
            ->and($operation['responses'][200]['content']['application/json']['schema']['properties']['data']['items'])
            ->toBe(['$ref' => '#/components/schemas/Space']);
    });

    it('documents the idempotency key and the write scope on a POST', function () {
        $operation = generatedSpec()['paths']['/fixtures']['post'];
        $header = collect($operation['parameters'])->firstWhere('name', 'Idempotency-Key');

        expect($header['in'])->toBe('header')
            ->and($header['required'] ?? false)->toBeFalse()
            ->and($operation['x-token-ability'])->toBe('api:write')
            ->and(array_keys($operation['responses']))->toContain(400, 409, 422);
    });

    it('documents the 404 of a route with a path parameter', function () {
        $operation = generatedSpec()['paths']['/fixtures/{fixture}']['delete'];

        expect($operation['x-token-ability'])->toBe('api:write')
            ->and($operation['responses'][404])->toBe(['$ref' => '#/components/responses/NotFound'])
            ->and(collect($operation['parameters'])->pluck('name'))->not->toContain('Idempotency-Key');
    });
});
