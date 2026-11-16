<?php

use App\Enums\OAuthApprovalRefusal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\Client as PassportClient;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/*
| The owner's claude.ai connector signs in to `/mcp/oauth` with OAuth. These
| walk what it does, as it does it, and pin what the OAuth hardening taken from
| upstream must not change for a connection that already exists.
*/

/**
 * A `tools/call` for list_spaces, the bearer token as the only credential.
 */
function callConnector(string $accessToken): TestResponse
{
    forgetSignedInUser();

    return withHeaders(['Authorization' => "Bearer {$accessToken}"])->postJson('/mcp/oauth', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list_spaces', 'arguments' => (object) []],
    ]);
}

/**
 * Register the connector through dynamic client registration.
 */
function registerConnector(): PassportClient
{
    $registered = postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [CLAUDE_CALLBACK],
    ])->assertCreated()->json();

    return Passport::client()->newQuery()->findOrFail($registered['client_id']);
}

/**
 * Sign the user in through the consent screen and exchange the code.
 *
 * @param  list<string>|null  $scopes
 * @return array{access_token: string, refresh_token: string}
 */
function connectConnector(User $user, PassportClient $client, ?array $scopes): array
{
    [$verifier, $challenge] = pkcePair();

    $consent = requestOAuthConsent($user, $client, $scopes, $challenge)->assertOk();

    return exchangeConnectorCode($client, $verifier, approveOAuthConsent($user, $client, $consent)->assertRedirect());
}

/**
 * @return array{access_token: string, refresh_token: string}
 */
function exchangeConnectorCode(PassportClient $client, string $verifier, TestResponse $approved): array
{
    return post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->id,
        'redirect_uri' => CLAUDE_CALLBACK,
        'code_verifier' => $verifier,
        'code' => oauthRedirectQuery($approved)['code'] ?? null,
    ])->assertOk()->json();
}

/**
 * The scopes of the authorization request waiting in the session for approval.
 *
 * @return list<string>
 */
function scopesAwaitingApproval(): array
{
    return array_map(
        fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
        unserialize(session('authRequest'))->getScopes(),
    );
}

/**
 * Rewrite the authorization request waiting in the session, as one stored
 * before this change would look.
 *
 * @param  list<ScopeEntityInterface>  $scopes
 */
function storeScopesAwaitingApproval(array $scopes): void
{
    $authRequest = unserialize(session('authRequest'));
    $authRequest->setScopes($scopes);

    session()->put('authRequest', serialize($authRequest));
}

/**
 * @return array{access_token: string, refresh_token: string}
 */
function refreshConnector(PassportClient $client, string $refreshToken, ?string $scope = null): array
{
    return post('/oauth/token', array_filter([
        'grant_type' => 'refresh_token',
        'client_id' => $client->id,
        'refresh_token' => $refreshToken,
        'scope' => $scope,
    ], fn (?string $value): bool => $value !== null))->assertOk()->json();
}

/**
 * An authorization request with `prompt=none`: approved at once or refused,
 * never a consent screen.
 */
function requestSilentAuthorization(User $user, PassportClient $client, string $scope): TestResponse
{
    [, $challenge] = pkcePair();

    return test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->id,
        'redirect_uri' => $client->redirect_uris[0],
        'response_type' => 'code',
        'scope' => $scope,
        'state' => 'state-token',
        'prompt' => 'none',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]));
}

/**
 * Run the callback with Passport's own scope repository, whatever is bound.
 *
 * @template TResult
 *
 * @param  Closure(): TResult  $callback
 * @return TResult
 */
function withStockScopeRepository(Closure $callback): mixed
{
    $binding = app()->getBindings()[ScopeRepository::class] ?? null;

    app()->bind(ScopeRepository::class, fn ($app): ScopeRepository => new ScopeRepository($app->make(ClientRepository::class)));
    app()->forgetInstance(AuthorizationServer::class);

    try {
        return $callback();
    } finally {
        app()->offsetUnset(ScopeRepository::class);

        if ($binding !== null) {
            app()->bind(ScopeRepository::class, $binding['concrete'], $binding['shared']);
        }

        app()->forgetInstance(AuthorizationServer::class);
    }
}

/**
 * A connection as it was authorized before `mcp:use` became the default scope:
 * a connector that asked for no scope, or for `*`, got a token with none.
 *
 * @return array{access_token: string, refresh_token: string}
 */
function connectBeforeDefaultScope(User $user, PassportClient $client): array
{
    [$verifier, $challenge] = pkcePair();

    $consent = requestOAuthConsent($user, $client, ['mcp:use'], $challenge)->assertOk();
    storeScopesAwaitingApproval([]);

    return withStockScopeRepository(fn (): array => exchangeConnectorCode(
        $client,
        $verifier,
        approveOAuthConsent($user, $client, $consent)->assertRedirect(),
    ));
}

it('walks the connector from discovery to a refreshed token', function () {
    $resource = get('/.well-known/oauth-protected-resource/mcp/oauth')->assertOk()->json();
    $server = get('/.well-known/oauth-authorization-server')->assertOk()->json();

    expect($resource['scopes_supported'])->toBe(['mcp:use'])
        ->and($server['scopes_supported'])->toBe(['mcp:use'])
        ->and($server['grant_types_supported'])->toBe(['authorization_code', 'refresh_token']);

    $user = User::factory()->create();
    $client = registerConnector();

    $tokens = connectConnector($user, $client, $resource['scopes_supported']);
    callConnector($tokens['access_token'])->assertOk()->assertSee('Personal', false);

    $refreshed = refreshConnector($client, $tokens['refresh_token']);
    callConnector($refreshed['access_token'])->assertOk()->assertSee('Personal', false);

    expect(Token::query()->where('user_id', $user->id)->pluck('scopes')->all())
        ->toBe([['mcp:use'], ['mcp:use']]);
});

it('connects a client that requests every scope the authorization server lists', function () {
    $scopes = get('/.well-known/oauth-authorization-server')->assertOk()->json('scopes_supported');

    $tokens = connectConnector(User::factory()->create(), registerConnector(), $scopes);

    callConnector($tokens['access_token'])->assertOk();
});

it('connects a client that requests no scope at all', function () {
    $tokens = connectConnector(User::factory()->create(), registerConnector(), null);

    callConnector($tokens['access_token'])->assertOk();
});

it('reconnects without asking again while the connection is alive', function () {
    $user = User::factory()->create();
    $client = registerConnector();
    connectConnector($user, $client, ['mcp:use']);
    [, $challenge] = pkcePair();

    $response = requestOAuthConsent($user, $client, ['mcp:use'], $challenge)->assertRedirect();

    expect(oauthRedirectQuery($response))->toHaveKey('code');
});

it('serves each resource its own scope with cached routes, as production runs', function (string $path, string $scope) {
    $request = Request::create($path);
    $route = Route::getRoutes()->match($request);
    $route->prepareForSerialization();

    /** @var IlluminateRoute $restored */
    $restored = unserialize(serialize($route));
    $restored->setRouter(app('router'))->setContainer(app())->bind($request);
    app()->instance(IlluminateRoute::class, $restored);

    expect($restored->run()->getData(true)['scopes_supported'])->toBe([$scope]);
})->with([
    'MCP' => ['/.well-known/oauth-protected-resource/mcp/oauth', 'mcp:use'],
    'API' => ['/.well-known/oauth-protected-resource/api/v1', 'api:use'],
]);

describe('connections authorized before this change', function () {
    it('keeps working when they carry no scope, refreshed or not', function () {
        $user = User::factory()->create();
        $client = registerConnector();

        $tokens = connectBeforeDefaultScope($user, $client);
        expect(Token::query()->sole()->scopes)->toBe([]);

        callConnector($tokens['access_token'])->assertOk()->assertSee('Personal', false);

        $refreshed = refreshConnector($client, $tokens['refresh_token']);
        callConnector($refreshed['access_token'])->assertOk()->assertSee('Personal', false);
    });

    it('stay out of the API when they carry no scope', function () {
        $tokens = connectBeforeDefaultScope(User::factory()->create(), registerConnector());
        forgetSignedInUser();

        withHeaders(['Authorization' => "Bearer {$tokens['access_token']}"])
            ->getJson('/api/v1/spaces')
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
    });

    it('are listed and can be disconnected when they carry no scope', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        $tokens = connectBeforeDefaultScope($user, $client);

        actingAs($user)->get(route('mcp.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('connectedApps', 1)
                ->where('connectedApps.0.id', $client->id)
                ->where('connectedApps.0.scopes', ['AI Connector']));

        actingAs($user)->delete(route('mcp.apps.destroy', $client->id))->assertRedirect();

        callConnector($tokens['access_token'])->assertUnauthorized();
    });
});

describe('a wildcard or blank scope', function () {
    it('is treated as mcp:use, so a client granted only api:use sees the MCP consent screen', function (array $scopes) {
        $user = User::factory()->create();
        $client = oauthClient('Budget Sync', 'https://budget-sync.test/callback');
        issueOAuthToken($user, ['api:use'], $client);
        [, $challenge] = pkcePair();

        requestOAuthConsent($user, $client, $scopes, $challenge)
            ->assertOk()
            ->assertSee('is asking to read and make changes', false)
            ->assertDontSee('API with your account', false)
            ->assertSee('id="authorizeForm"', false);
    })->with([
        'wildcard' => [['*']],
        'blank' => [[]],
        'space' => [[' ']],
    ]);

    it('issues a token that carries mcp:use and works, never an empty set', function (array $scopes) {
        $user = User::factory()->create();

        $tokens = connectConnector($user, registerConnector(), $scopes);

        expect(Token::query()->where('user_id', $user->id)->sole()->scopes)->toBe(['mcp:use']);
        callConnector($tokens['access_token'])->assertOk()->assertSee('Personal', false);
    })->with([
        'wildcard' => [['*']],
        'blank' => [[]],
        'space' => [[' ']],
        'wildcard next to mcp:use' => [['*', 'mcp:use']],
    ]);

    it('keeps a connector already holding mcp:use working silently', function (array $scopes) {
        $user = User::factory()->create();
        $client = registerConnector();
        connectConnector($user, $client, ['mcp:use']);
        [$verifier, $challenge] = pkcePair();

        $response = requestOAuthConsent($user, $client, $scopes, $challenge)->assertRedirect();
        $code = oauthRedirectQuery($response)['code'] ?? null;
        expect($code)->not->toBeNull();

        $tokens = post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'redirect_uri' => CLAUDE_CALLBACK,
            'code_verifier' => $verifier,
            'code' => $code,
        ])->assertOk()->json();

        callConnector($tokens['access_token'])->assertOk();
        expect(Token::query()->where('user_id', $user->id)->pluck('scopes')->unique()->all())->toBe([['mcp:use']]);
    })->with([
        'wildcard' => [['*']],
        'blank' => [[]],
    ]);

    it('treats every spelling League reads as a wildcard the same, through the controller', function (string $scope) {
        $user = User::factory()->create();
        $client = oauthClient('Budget Sync', 'https://budget-sync.test/callback');
        issueOAuthToken($user, ['api:use'], $client);
        [, $challenge] = pkcePair();

        requestOAuthConsent($user, $client, [$scope], $challenge)
            ->assertOk()
            ->assertSee('is asking to read and make changes', false)
            ->assertSee('id="authorizeForm"', false);

        expect(scopesAwaitingApproval())->toBe(['mcp:use']);
    })->with([
        'tab after' => "*\t",
        'tab before' => "\t*",
        'newline' => "*\n",
        'carriage return' => "*\r",
        'vertical tab' => "*\x0B",
        'NUL' => "*\0",
    ]);

    it('never approves a request hiding a wildcard behind a control character', function (string $scope) {
        $user = User::factory()->create();
        $client = oauthClient('Budget Sync', 'https://budget-sync.test/callback');
        issueOAuthToken($user, ['api:use'], $client);
        [, $challenge] = pkcePair();

        $response = requestOAuthConsent($user, $client, [$scope], $challenge);

        $response->assertRedirect();
        expect(oauthRedirectQuery($response))
            ->toMatchArray(['error' => 'invalid_scope'])
            ->not->toHaveKey('code');
    })->with([
        'tab, space, wildcard' => "*\t *",
        'space, tab' => "* \t*",
        'zero, space, tab' => "0 \t*",
        'newline' => "*\n *",
        'carriage return' => "*\r *",
        'vertical tab' => "*\x0B *",
        'NUL' => "*\0 *",
    ]);

    it('corrects the request in the session, so approval and the token read mcp:use', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        [$verifier, $challenge] = pkcePair();

        $consent = requestOAuthConsent($user, $client, ['*'], $challenge)->assertOk();
        expect(scopesAwaitingApproval())->toBe(['mcp:use']);

        $tokens = exchangeConnectorCode($client, $verifier, approveOAuthConsent($user, $client, $consent)->assertRedirect());

        expect(Token::query()->where('user_id', $user->id)->sole()->scopes)->toBe(['mcp:use']);
        callConnector($tokens['access_token'])->assertOk();
    });

    it('answers prompt=none with consent_required for a client granted only api:use', function () {
        $user = User::factory()->create();
        $client = oauthClient('Budget Sync', 'https://budget-sync.test/callback');
        issueOAuthToken($user, ['api:use'], $client);

        $response = requestSilentAuthorization($user, $client, '*')->assertRedirect();

        expect(oauthRedirectQuery($response))
            ->toMatchArray(['error' => 'consent_required'])
            ->not->toHaveKey('code');
    });

    it('answers prompt=none with a code for a connector already holding mcp:use', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        connectConnector($user, $client, ['mcp:use']);

        $response = requestSilentAuthorization($user, $client, '*')->assertRedirect();

        expect(oauthRedirectQuery($response))->toHaveKey('code');
    });

    it('never treats a request with no scope as already granted', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        connectConnector($user, $client, ['mcp:use']);

        $controller = app(PassportAuthorizationController::class);

        expect((fn (): bool => $this->hasGrantedScopes($user, $client, []))->call($controller))->toBeFalse();
    });

    it('leaves a wildcard next to api:use on the API surface', function () {
        $user = User::factory()->create();

        $tokens = connectConnector($user, registerConnector(), ['*', 'api:use']);

        expect(Token::query()->where('user_id', $user->id)->sole()->scopes)->toBe(['api:use']);
        callConnector($tokens['access_token'])->assertForbidden();
    });

    it('still refuses a wildcard next to both surfaces', function () {
        [, $challenge] = pkcePair();

        $response = requestOAuthConsent(User::factory()->create(), registerConnector(), ['*', 'mcp:use', 'api:use'], $challenge);

        expect(oauthRedirectQuery($response))->toMatchArray(['error' => 'invalid_scope'])->not->toHaveKey('code');
    });
});

describe('refreshing', function () {
    it('keeps the scopes of a token refreshed with a blank scope', function (array $scopes) {
        $user = User::factory()->create();
        $client = registerConnector();
        $tokens = connectConnector($user, $client, $scopes);

        refreshConnector($client, $tokens['refresh_token'], ' ');

        expect(Token::query()->where('user_id', $user->id)->pluck('scopes')->unique()->all())->toBe([$scopes]);
    })->with([
        'mcp:use' => [['mcp:use']],
        'api:use' => [['api:use']],
    ]);

    it('refuses a wildcard, which no token holds, and leaves the token as it was', function (array $scopes) {
        $client = registerConnector();
        $tokens = connectConnector(User::factory()->create(), $client, $scopes);

        post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $tokens['refresh_token'],
            'scope' => '*',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_scope');

        expect(refreshConnector($client, $tokens['refresh_token'])['access_token'])->not->toBeEmpty();
        expect(Token::query()->where('revoked', false)->sole()->scopes)->toBe($scopes);
    })->with([
        'mcp:use' => [['mcp:use']],
        'api:use' => [['api:use']],
    ]);

    it('never lets an api:use token reach MCP, refreshed or not', function () {
        $client = registerConnector();
        $tokens = connectConnector(User::factory()->create(), $client, ['api:use']);

        callConnector($tokens['access_token'])->assertForbidden();

        $refreshed = refreshConnector($client, $tokens['refresh_token']);

        callConnector($refreshed['access_token'])->assertForbidden();
    });

    it('keeps an empty-scope token working when it refreshes with a blank scope', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        $tokens = connectBeforeDefaultScope($user, $client);

        $refreshed = refreshConnector($client, $tokens['refresh_token'], ' ');

        callConnector($refreshed['access_token'])->assertOk();
        expect(Token::query()->where('revoked', false)->sole()->scopes)->toBe([]);
    });

    it('still lets a client narrow to a scope it holds, and refuses one it does not', function () {
        $client = registerConnector();
        $tokens = connectConnector(User::factory()->create(), $client, ['mcp:use']);

        post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->id,
            'refresh_token' => $tokens['refresh_token'],
            'scope' => 'api:use',
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_scope');

        $refreshed = refreshConnector($client, $tokens['refresh_token'], 'mcp:use');

        callConnector($refreshed['access_token'])->assertOk();
    });
});

describe('issuing a token', function () {
    /**
     * @param  list<Scope>  $scopes
     * @return list<string>
     */
    function finalizedScopes(array $scopes, string $grantType): array
    {
        $finalized = app(ScopeRepository::class)->finalizeScopes($scopes, $grantType, new Client(oauthClient()->id), 'user-id');

        return array_map(fn (Scope $scope): string => $scope->getIdentifier(), $finalized);
    }

    it('gives an authorization code that reaches it with no scope mcp:use', function () {
        expect(finalizedScopes([], 'authorization_code'))->toBe(['mcp:use'])
            ->and(finalizedScopes([new Scope('*')], 'authorization_code'))->toBe(['mcp:use']);
    });

    it('keeps the scopes of an authorization code that has them', function () {
        expect(finalizedScopes([new Scope('api:use')], 'authorization_code'))->toBe(['api:use']);
    });

    it('leaves a refresh alone, so an empty-scope token stays what it was', function () {
        expect(finalizedScopes([], 'refresh_token'))->toBe([]);
    });

    it('refuses a code whose surface scope was dropped, instead of turning it into MCP', function (string $grantType) {
        $registered = Passport::$scopes;
        Passport::$scopes = ['mcp:use' => 'Use MCP server'];

        try {
            expect(fn () => finalizedScopes([new Scope('api:use')], $grantType))->toThrow(OAuthServerException::class);
        } finally {
            Passport::$scopes = $registered;
        }
    })->with(['authorization_code', 'refresh_token']);

    it('issues mcp:use from a consent screen left open across the deploy', function (array $scopes) {
        $user = User::factory()->create();
        $client = registerConnector();
        [$verifier, $challenge] = pkcePair();

        $consent = requestOAuthConsent($user, $client, ['mcp:use'], $challenge)->assertOk();
        storeScopesAwaitingApproval($scopes);

        exchangeConnectorCode($client, $verifier, approveOAuthConsent($user, $client, $consent)->assertRedirect());

        expect(Token::query()->where('user_id', $user->id)->sole()->scopes)->toBe(['mcp:use']);
    })->with([
        'no scope' => [[]],
        'wildcard' => fn () => [new Scope('*')],
    ]);
});

describe('dynamic client registration', function () {
    it('rate limits registrations per IP without touching a normal connector', function () {
        foreach (range(1, 10) as $attempt) {
            registerConnector();
        }

        postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [CLAUDE_CALLBACK]])->assertTooManyRequests();

        postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [CLAUDE_CALLBACK]], ['REMOTE_ADDR' => '203.0.113.9'])->assertCreated();
    });

    it('keeps the hardened controller and the limiter on the one registered route', function () {
        $route = Route::getRoutes()->match(Request::create('/oauth/register', 'POST'));

        expect($route->middleware())->toContain('throttle:oauth-register');
        expect(RateLimiter::limiter('oauth-register'))->not->toBeNull();
    });
});

describe('consent screen', function () {
    /**
     * What the screen showed before it moved to `oauth.authorize`, by language
     * key, so nothing the owner reads when connecting is lost.
     *
     * @return array<string, array<string, string>>
     */
    function consentCopy(): array
    {
        return [
            'Connect :client to :app' => ['client' => 'Claude', 'app' => config('app.name')],
            ':client is asking to read and make changes to your finance data.' => ['client' => 'Claude'],
            'Signed in as' => [],
            'Sends you back to' => [],
            'This connection can:' => [],
            'Read and analyse your transactions, balances, categories and spending.' => [],
            'Create, edit and delete transactions, categories, labels and automation rules.' => [],
            'Transactions synced from your bank cannot be edited or deleted, and bank-connected balances stay untouched. You can disconnect it at any time from the connected app.' => [],
            'Cancel' => [],
            'Connect' => [],
            'Connecting…' => [],
        ];
    }

    it('keeps everything the old screen showed', function (string $locale) {
        $user = User::factory()->create(['locale' => $locale, 'email' => 'owner@example.test']);
        app()->setLocale($locale);
        [, $challenge] = pkcePair();

        $response = requestOAuthConsent($user, registerConnector(), ['mcp:use'], $challenge)
            ->assertOk()
            ->assertSee('owner@example.test', false)
            ->assertSee('https://claude.ai', false)
            ->assertSee('name="auth_token"', false)
            ->assertSee('id="authorizeForm"', false)
            // Self-hosted fonts only: the fork's screen calls no third party.
            ->assertDontSee('fonts.bunny.net', false);

        foreach (consentCopy() as $key => $replacements) {
            $response->assertSee(__($key, $replacements, $locale), false);
        }
    })->with(['en', 'es']);

    it('has every string of the screen in Spanish', function () {
        $translations = json_decode(File::get(lang_path('es.json')), true);

        preg_match_all("/__\\('([^']+)'/", File::get(resource_path('views/oauth/authorize.blade.php')), $matches);

        expect($matches[1])->not->toBeEmpty();

        foreach ($matches[1] as $key) {
            expect(array_key_exists($key, $translations))->toBeTrue("Missing translation: {$key}");
        }
    });

    it('has the refusal reasons in Spanish', function (OAuthApprovalRefusal $refusal) {
        app()->setLocale('en');
        [$title, $message] = [$refusal->title(), $refusal->message()];

        app()->setLocale('es');

        expect($refusal->title())->not->toBe($title)
            ->and($refusal->message())->not->toBe($message);
    })->with(OAuthApprovalRefusal::cases());
});

describe('framing', function () {
    it('forbids framing only, so approving still redirects back to the connector', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        [, $challenge] = pkcePair();

        $consent = requestOAuthConsent($user, $client, ['mcp:use'], $challenge)
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderContains('Content-Security-Policy', "frame-ancestors 'none'");

        $approved = approveOAuthConsent($user, $client, $consent)->assertRedirect();

        expect((string) $approved->headers->get('Location'))->toStartWith(CLAUDE_CALLBACK.'?')
            ->and(oauthRedirectQuery($approved))->toHaveKey('code')
            ->and(oauthRedirectQuery($approved)['state'])->toBe('state-token');
    });

    it('still lets the user cancel', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        [, $challenge] = pkcePair();

        $consent = requestOAuthConsent($user, $client, ['mcp:use'], $challenge)->assertOk();
        preg_match('/name="auth_token" value="([^"]+)"/', $consent->getContent(), $matches);

        $denied = test()->actingAs($user)->delete('/oauth/authorize', [
            'auth_token' => $matches[1],
            'client_id' => $client->id,
            'state' => 'state-token',
        ])->assertRedirect();

        expect((string) $denied->headers->get('Location'))->toStartWith(CLAUDE_CALLBACK)
            ->and(oauthRedirectQuery($denied)['error'] ?? null)->toBe('access_denied');
    });

    it('leaves the machine-to-machine OAuth endpoints answering as before', function () {
        $server = get('/.well-known/oauth-authorization-server')->assertOk();

        expect($server->headers->has('X-Frame-Options'))->toBeFalse();

        $client = registerConnector();
        $tokens = connectConnector(User::factory()->create(), $client, ['mcp:use']);

        expect($tokens['access_token'])->not->toBeEmpty();
    });
});
