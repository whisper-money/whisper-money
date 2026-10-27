<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

beforeEach(fn () => registerApiV1TestRoutes());

describe('rate limit', function () {
    it('allows 60 requests a minute per user and reports the budget in headers', function () {
        $user = User::factory()->create();
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/spaces')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59');

        for ($i = 2; $i <= 60; $i++) {
            withHeaders($headers)->getJson('/api/v1/spaces')->assertOk();
        }

        withHeaders($headers)->getJson('/api/v1/spaces')
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_requests');

        // Another user has a bucket of their own.
        withHeaders(apiV1Bearer(User::factory()->create()))->getJson('/api/v1/spaces')->assertOk();
    });
});

describe('CORS', function () {
    it('answers a preflight from any origin on the public API, without credentials', function () {
        call('OPTIONS', '/api/v1/spaces', server: [
            'HTTP_ORIGIN' => 'https://some-spa.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, idempotency-key',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    });

    it('exposes the rate-limit headers to browser clients', function () {
        withHeaders([...apiV1Bearer(User::factory()->create()), 'Origin' => 'https://some-spa.example'])
            ->getJson('/api/v1/spaces')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Expose-Headers', 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, Idempotent-Replayed');
    });

    it('keeps the internal session API same-origin', function () {
        $response = withHeaders(['Origin' => 'https://some-spa.example'])->getJson('/api/transactions');

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    });
});

describe('idempotency', function () {
    it('replays the first response for a repeated key instead of running the request again', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];

        $first = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertCreated();

        expect($replay->getContent())->toBe($first->getContent());
        $replay->assertHeader('Idempotent-Replayed', 'true');
        $first->assertHeaderMissing('Idempotent-Replayed');
    });

    it('runs the request every time without a key', function () {
        $headers = apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']);

        $first = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips']);
        $second = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips']);

        expect($first->json('data.id'))->not->toBe($second->json('data.id'));
    });

    it('rejects a key reused with a different body', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];

        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertCreated();

        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Holidays'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Idempotency-Key');
    });

    it('rejects a key reused against another query string', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];

        withHeaders($headers)->postJson('/api/v1/_test/echo?space=a', ['name' => 'Trips'])->assertCreated();

        withHeaders($headers)->postJson('/api/v1/_test/echo?space=b', ['name' => 'Trips'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Idempotency-Key');
    });

    it('does not keep a failed response, so the client can fix the body and retry with the same key', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];

        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Far too long a name'])->assertUnprocessable();

        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');
    });

    it('scopes keys to the user', function () {
        $body = ['name' => 'Trips'];
        $first = withHeaders([...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'k'])
            ->postJson('/api/v1/_test/echo', $body);
        $other = withHeaders([...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'k'])
            ->postJson('/api/v1/_test/echo', $body);

        expect($other->json('data.id'))->not->toBe($first->json('data.id'));
        $other->assertHeaderMissing('Idempotent-Replayed');
    });

    it('rejects a key longer than 255 characters', function () {
        withHeaders([...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => str_repeat('k', 256)])
            ->postJson('/api/v1/_test/echo')
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_idempotency_key');
    });
});

describe('errors', function () {
    it('answers an unknown endpoint with the JSON error shape, even without an Accept header', function () {
        $this->get('/api/v1/nope')
            ->assertNotFound()
            ->assertExactJson(['message' => 'The route api/v1/nope could not be found.', 'code' => 'not_found']);
    });

    it('answers a wrong method with the JSON error shape', function () {
        withHeaders(apiV1Bearer(User::factory()->create()))
            ->deleteJson('/api/v1/spaces')
            ->assertMethodNotAllowed()
            ->assertJsonPath('code', 'method_not_allowed');
    });

    it('hides the internals of a server error', function () {
        Log::spy();

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->get('/api/v1/_test/boom')
            ->assertInternalServerError()
            ->assertExactJson(['message' => 'Internal Server Error', 'code' => 'internal_server_error']);
    });

    it('keeps the standard validation body for 422s', function () {
        $foreign = User::factory()->create()->personalSpace;

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->get("/api/v1/_test/space?space={$foreign->id}")
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => "You do not have access to a space with id {$foreign->id}. List the valid ids with GET /api/v1/spaces.",
                'errors' => ['space' => ["You do not have access to a space with id {$foreign->id}. List the valid ids with GET /api/v1/spaces."]],
            ]);
    });

    it('leaves the internal API errors untouched', function () {
        getJson('/api/transactions')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    });
});
