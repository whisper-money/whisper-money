<?php

use App\Enums\OAuthScope;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * A first-party client, approved without showing the consent screen.
 */
class FirstPartyClient extends Client
{
    public function skipsAuthorization(Authenticatable $user, array $scopes): bool
    {
        return true;
    }
}

/**
 * A `tools/call` for list_spaces at `/mcp/oauth` with the bearer token.
 */
function callMcpOAuth(string $token): TestResponse
{
    return withHeaders(['Authorization' => "Bearer {$token}"])->postJson('/mcp/oauth', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list_spaces', 'arguments' => (object) []],
    ]);
}

describe('scope registration', function () {
    it('registers both scopes', function () {
        expect(Passport::hasScope('mcp:use'))->toBeTrue()
            ->and(Passport::hasScope('api:use'))->toBeTrue();
    });

    it('keeps both scopes whichever registers first', function (Closure $first, Closure $second) {
        Passport::$scopes = [];

        $first();
        $second();

        expect(array_keys(Passport::$scopes))->toEqualCanonicalizing(['mcp:use', 'api:use']);
    })->with([
        'laravel/mcp first' => [fn () => Registrar::ensureMcpScope(), fn () => OAuthScope::register()],
        'the app first' => [fn () => OAuthScope::register(), fn () => Registrar::ensureMcpScope()],
        'the app, then the bound MCP registrar' => [fn () => OAuthScope::register(), fn () => app(Registrar::class)::ensureMcpScope()],
    ]);
});

describe('discovery', function () {
    it('advertises each protected resource with its own scope', function (string $resource, string $scope) {
        expect(get("/.well-known/oauth-protected-resource/{$resource}")->assertOk()->json('scopes_supported'))
            ->toBe([$scope]);
    })->with([
        'MCP' => ['mcp/oauth', 'mcp:use'],
        'API' => ['api/v1', 'api:use'],
    ]);

    it('lists both scopes on the nested authorization server metadata too', function () {
        expect(get('/.well-known/oauth-authorization-server/mcp/oauth')->assertOk()->json('scopes_supported'))
            ->toBe(['mcp:use', 'api:use']);
    });
});

describe('authorization requests', function () {
    it('gives a request with no scope the mcp:use scope, as connectors always got', function () {
        $user = User::factory()->create();
        $token = issueOAuthToken($user, null);
        forgetSignedInUser();

        expect(Token::query()->where('user_id', $user->id)->sole()->scopes)->toBe(['mcp:use']);
        callMcpOAuth($token)->assertOk()->assertSee('Personal', false);
    });

    it('refuses a request for both scopes before showing the consent screen', function () {
        [, $challenge] = pkcePair();

        $response = requestOAuthConsent(User::factory()->create(), oauthClient(), ['mcp:use', 'api:use'], $challenge);

        $response->assertRedirect();
        expect((string) $response->headers->get('Location'))->toStartWith(CLAUDE_CALLBACK.'?');
        expect(oauthRedirectQuery($response))
            ->toMatchArray(['error' => 'invalid_scope', 'state' => 'state-token'])
            ->not->toHaveKey('code');
        expect(Token::query()->count())->toBe(0);
    });
});

describe('end to end', function () {
    it('registers through DCR, authorizes api:use with PKCE and reaches the API but not MCP', function () {
        $user = User::factory()->create();
        $callback = 'http://localhost:53682/callback';

        $registered = postJson('/oauth/register', [
            'client_name' => 'Budget Sync',
            'redirect_uris' => [$callback],
        ])->assertCreated()->json();

        $token = issueOAuthToken($user, ['api:use'], Passport::client()->newQuery()->findOrFail($registered['client_id']));
        forgetSignedInUser();

        withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/spaces')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Personal');

        forgetSignedInUser();

        callMcpOAuth($token)->assertForbidden();
    });

    it('keeps an mcp:use connection out of the API', function () {
        $token = issueOAuthToken(User::factory()->create(), ['mcp:use']);
        forgetSignedInUser();

        withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/spaces')
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
    });
});

describe('consent screen', function () {
    it('words the screen for the scope requested', function (string $scope, string $expected, string $unexpected) {
        [, $challenge] = pkcePair();

        requestOAuthConsent(User::factory()->create(), oauthClient('Budget Sync'), [$scope], $challenge)
            ->assertOk()
            ->assertSee($expected, false)
            ->assertDontSee($unexpected, false)
            ->assertSee('https://claude.ai', false)
            ->assertSee('Unverified app', false)
            ->assertSee('read and write access to your finances', false)
            ->assertSee('id="authorizeForm"', false);
    })->with([
        'MCP' => ['mcp:use', 'connect your AI assistant', 'API with your account'],
        'API' => ['api:use', 'API with your account', 'connect your AI assistant'],
    ]);

    it('forbids framing the consent screen', function () {
        [, $challenge] = pkcePair();

        requestOAuthConsent(User::factory()->create(), oauthClient(), ['api:use'], $challenge)
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
    });

    it('shows the API wording in Spanish', function () {
        [, $challenge] = pkcePair();
        $user = User::factory()->create(['locale' => 'es']);
        app()->setLocale('es');

        requestOAuthConsent($user, oauthClient('Budget Sync'), ['api:use'], $challenge)
            ->assertOk()
            ->assertSee('App no verificada', false);
    });
});

describe('device authorization', function () {
    it('is not offered, since it would skip the scope rules', function () {
        expect(Route::has('passport.device'))->toBeFalse()
            ->and(Route::has('passport.device.authorizations.approve'))->toBeFalse();
    });
});

describe('who may approve api:use', function () {
    beforeEach(fn () => config(['subscriptions.enabled' => true]));

    it('shows an upsell instead of the approve button without the API plan', function () {
        [, $challenge] = pkcePair();

        requestOAuthConsent(User::factory()->create(), oauthClient(), ['api:use'], $challenge)
            ->assertOk()
            ->assertSee('The API is available on a paid plan', false)
            ->assertSee(route('subscribe'), false)
            ->assertDontSee('id="authorizeForm"', false);
    });

    it('still lets a free user approve an MCP connection', function () {
        [, $challenge] = pkcePair();

        requestOAuthConsent(User::factory()->create(), oauthClient(), ['mcp:use'], $challenge)
            ->assertOk()
            ->assertSee('id="authorizeForm"', false);
    });

    it('tells a shared account it cannot connect API apps', function (string $account) {
        config(["app.{$account}.email" => "{$account}@example.test"]);
        [, $challenge] = pkcePair();

        requestOAuthConsent(User::factory()->create(['email' => "{$account}@example.test"]), oauthClient(), ['api:use'], $challenge)
            ->assertOk()
            ->assertSee('This account cannot connect apps to the API', false)
            ->assertDontSee('id="authorizeForm"', false);
    })->with(['demo', 'press']);

    it('refuses an approval posted by hand with access_denied', function () {
        $user = User::factory()->create();
        $client = oauthClient();
        [, $challenge] = pkcePair();

        // Render the consent screen with an approvable request, then lose the plan.
        config(['subscriptions.enabled' => false]);
        $consent = requestOAuthConsent($user, $client, ['api:use'], $challenge)->assertOk();
        config(['subscriptions.enabled' => true]);

        $response = approveOAuthConsent($user, $client, $consent)->assertRedirect();

        expect(oauthRedirectQuery($response))
            ->toMatchArray(['error' => 'access_denied', 'state' => 'state-token'])
            ->not->toHaveKey('code');
    });

    it('does not silently re-approve an earlier grant once the plan has lapsed', function () {
        $user = User::factory()->create();
        $client = oauthClient();

        config(['subscriptions.enabled' => false]);
        issueOAuthToken($user, ['api:use'], $client);
        config(['subscriptions.enabled' => true]);

        [, $challenge] = pkcePair();

        requestOAuthConsent($user, $client, ['api:use'], $challenge)
            ->assertOk()
            ->assertSee('The API is available on a paid plan', false);
    });

    it('refuses a client that skips the consent screen when the user may not approve', function () {
        Passport::useClientModel(FirstPartyClient::class);
        $client = oauthClient();
        [, $challenge] = pkcePair();

        $response = requestOAuthConsent(User::factory()->create(), $client, ['api:use'], $challenge)->assertRedirect();

        expect(oauthRedirectQuery($response))
            ->toMatchArray(['error' => 'access_denied'])
            ->not->toHaveKey('code');

        Passport::useClientModel(Client::class);
    });

    it('re-approves an earlier grant silently while the plan holds', function () {
        $user = User::factory()->subscribed()->create();
        $client = oauthClient();
        issueOAuthToken($user, ['api:use'], $client);
        [, $challenge] = pkcePair();

        $response = requestOAuthConsent($user, $client, ['api:use'], $challenge)->assertRedirect();

        expect(oauthRedirectQuery($response))->toHaveKey('code');
    });
});
