<?php

use App\Models\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

beforeEach(fn () => registerApiV1TestRoutes());

describe('personal access tokens', function () {
    it('lets a token reach the API only with the abilities the method needs', function (array $abilities, string $method, int $status) {
        $user = User::factory()->create();
        $uri = $method === 'GET' ? '/api/v1/spaces' : '/api/v1/_test/echo';

        withHeaders(apiV1Bearer($user, $abilities))
            ->json($method, $uri)
            ->assertStatus($status);
    })->with([
        'read token, GET' => [['api:read'], 'GET', 200],
        'read token, POST' => [['api:read'], 'POST', 403],
        'read & write token, GET' => [['api:read', 'api:write'], 'GET', 200],
        'read & write token, POST' => [['api:read', 'api:write'], 'POST', 201],
    ]);

    it('explains that a read-only token cannot write', function () {
        withHeaders(apiV1Bearer(User::factory()->create()))
            ->postJson('/api/v1/_test/echo')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'This token is read-only. Create a read & write token to make changes.',
                'code' => 'insufficient_scope',
            ]);
    });

    it('rejects tokens issued for another surface or for everything', function (array $abilities) {
        withHeaders(apiV1Bearer(User::factory()->create(), $abilities))
            ->getJson('/api/v1/spaces')
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
    })->with([
        'MCP read' => [['mcp:read']],
        'MCP read & write' => [['mcp:read', 'mcp:write']],
        'wildcard' => [['*']],
    ]);

    it('rejects an expired token', function () {
        withHeaders(apiV1Bearer(User::factory()->create(), ['api:read'], now()->subMinute()))
            ->getJson('/api/v1/spaces')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });

    it('accepts a token that has not expired yet', function () {
        withHeaders(apiV1Bearer(User::factory()->create(), ['api:read'], now()->addDay()))
            ->getJson('/api/v1/spaces')
            ->assertOk();
    });

    it('rejects an unknown token', function () {
        withHeaders(['Authorization' => 'Bearer wm_api_not-a-real-token'])
            ->getJson('/api/v1/spaces')
            ->assertUnauthorized();
    });

    it('records when the token was last used', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))->getJson('/api/v1/spaces')->assertOk();

        expect($user->tokens()->sole()->last_used_at)->not->toBeNull();
    });
});

describe('OAuth access tokens', function () {
    it('accepts an access token granted the api:use scope, for reads and writes', function () {
        $user = User::factory()->create();
        Passport::actingAs($user, ['api:use']);

        getJson('/api/v1/spaces')->assertOk();
        postJson('/api/v1/_test/echo')->assertCreated();
    });

    it('rejects an access token that was not granted api:use', function (array $scopes) {
        Passport::actingAs(User::factory()->create(), $scopes);

        getJson('/api/v1/spaces')
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
    })->with([
        'MCP connection' => [['mcp:use']],
        'MCP and API together' => [['mcp:use', 'api:use']],
        'no scope' => [[]],
    ]);

    it('authenticates a real signed access token sent as a bearer', function (array $scopes, int $status) {
        $user = User::factory()->create();
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test personal access client', 'users');

        $jwt = app(PersonalAccessTokenFactory::class)
            ->make($user->getKey(), 'test', $scopes, 'users')
            ->accessToken;

        withHeaders(['Authorization' => "Bearer {$jwt}"])
            ->getJson('/api/v1/spaces')
            ->assertStatus($status);
    })->with([
        'api:use' => [['api:use'], 200],
        'mcp:use' => [['mcp:use'], 403],
    ]);
});

describe('anything but a bearer token', function () {
    it('rejects a request with no credentials', function () {
        getJson('/api/v1/spaces')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'unauthenticated']);
    });

    it('never authenticates a logged-in web session', function () {
        actingAs(User::factory()->create());

        getJson('/api/v1/spaces')->assertUnauthorized();
        postJson('/api/v1/_test/echo')->assertUnauthorized();
    });
});

describe('access gate', function () {
    it('refuses a user without a Pro plan, pointing at the subscribe page, and keeps the token', function () {
        config(['subscriptions.enabled' => true]);
        $user = User::factory()->create();
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/spaces')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'A paid (Pro) plan is required to use the Whisper Money API.',
                'code' => 'plan_required',
                'subscribe_url' => route('subscribe'),
            ]);

        expect($user->tokens()->count())->toBe(1);
    });

    it('lets a Pro subscriber through', function () {
        config(['subscriptions.enabled' => true]);

        withHeaders(apiV1Bearer(User::factory()->subscribed()->create()))
            ->getJson('/api/v1/spaces')
            ->assertOk();
    });

    it('applies the plan of the user who authorized an OAuth app', function () {
        config(['subscriptions.enabled' => true]);
        Passport::actingAs(User::factory()->create(), ['api:use']);

        getJson('/api/v1/spaces')->assertForbidden()->assertJsonPath('code', 'plan_required');
    });

    it('refuses the shared demo and press accounts', function (string $account) {
        config(["app.{$account}.email" => "{$account}@example.test"]);
        $user = User::factory()->create(['email' => "{$account}@example.test"]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/spaces')
            ->assertForbidden()
            ->assertJsonPath('code', 'shared_account');
    })->with(['demo', 'press']);
});
