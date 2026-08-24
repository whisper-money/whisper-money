<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\ListSpaces;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The press account is the second account whose credentials are public. Unlike
 * the demo account it is allowed to drive the MCP — that is what it exists for —
 * so these tests pin both halves: the MCP works, and nothing that could hijack
 * the shared login or spend money does.
 */
beforeEach(function () {
    config([
        'app.demo.email' => 'demo@whisper.money',
        'app.press.email' => 'prensa@whisper.money',
        'subscriptions.enabled' => true,
    ]);
});

/**
 * A press account as the reset command leaves it: verified, onboarded and
 * carrying the seeded (fake) Stripe subscription that satisfies the Pro gate.
 */
function pressAccount(): User
{
    $user = User::factory()->withoutTwoFactor()->create([
        'email' => 'prensa@whisper.money',
        'password' => '123456789',
        'locale' => 'es',
        'currency_code' => 'EUR',
        'onboarded_at' => now(),
    ]);

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => "sub_press_{$user->id}",
        'stripe_status' => 'active',
        'stripe_price' => 'price_press_free',
    ]);

    return $user;
}

it('recognises the press account without inheriting the demo restrictions', function () {
    $press = pressAccount();

    expect($press->isPressAccount())->toBeTrue()
        ->and($press->isDemoAccount())->toBeFalse()
        ->and($press->isRestrictedDemoAccount())->toBeFalse()
        ->and($press->isRestrictedSharedAccount())->toBeTrue()
        ->and($press->hasSeededSubscription())->toBeTrue()
        ->and($press->hasProPlan())->toBeTrue();
});

it('lets the press account read through the MCP', function () {
    WhisperMoneyServer::actingAs(pressAccount())
        ->tool(ListSpaces::class)
        ->assertOk();
});

it('lets the press account write through the MCP', function () {
    $press = pressAccount();
    $press->withAccessToken($press->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    WhisperMoneyServer::actingAs($press)
        ->tool(CreateLabel::class, ['name' => 'Prensa', 'color' => 'blue'])
        ->assertOk();

    expect($press->labels()->where('name', 'Prensa')->exists())->toBeTrue();
});

it('still refuses the MCP to the demo account', function () {
    $demo = User::factory()->create(['email' => 'demo@whisper.money']);

    WhisperMoneyServer::actingAs($demo)
        ->tool(ListSpaces::class)
        ->assertHasErrors()
        ->assertSee('demo account cannot be connected');
});

it('lets the press account manage its own MCP tokens', function () {
    $this->actingAs(pressAccount());

    $this->get(route('mcp.index'))->assertOk();

    $this->post(route('mcp.tokens.store'), ['name' => 'Claude', 'scope' => 'read_write'])
        ->assertRedirect(route('mcp.index'))
        ->assertSessionHasNoErrors();
});

it('keeps the demo account off the MCP token routes', function () {
    $demo = User::factory()->create(['email' => 'demo@whisper.money']);

    $this->actingAs($demo);

    $this->get(route('mcp.index'))->assertNotFound();
    $this->post(route('mcp.tokens.store'), ['name' => 'Claude', 'scope' => 'read_write'])
        ->assertSessionHasErrors('demo');
});

it('blocks the actions that would hijack or empty the press account', function () {
    $press = pressAccount();

    $this->actingAs($press);

    // Locking everyone else out.
    $this->post('/user/two-factor-authentication')->assertSessionHasErrors('demo');

    // Taking the login over.
    $this->put(route('user-password.update'), [
        'current_password' => '123456789',
        'password' => 'somethingelse123',
        'password_confirmation' => 'somethingelse123',
    ])->assertSessionHasErrors('demo');

    $this->patch(route('profile.update'), [
        'name' => 'Someone Else',
        'email' => 'someone@else.test',
    ])->assertSessionHasErrors('demo');

    $this->delete(route('profile.destroy'), ['password' => '123456789'])
        ->assertSessionHasErrors('demo');

    expect($press->fresh()->email)->toBe('prensa@whisper.money');
});

it('keeps a real bank off the press account', function () {
    $this->actingAs(pressAccount());

    $this->post(route('open-banking.authorize'), ['aspsp_name' => 'BBVA', 'aspsp_country' => 'ES'])
        ->assertSessionHasErrors('demo');

    $this->post(route('open-banking.binance.connect'), ['api_key' => 'k', 'api_secret' => 's'])
        ->assertSessionHasErrors('demo');
});

it('keeps AI consent and paid generations off the press account', function () {
    $press = pressAccount();

    $this->actingAs($press);

    $this->post(route('ai.consent.store'))->assertSessionHasErrors('demo');
    $this->post(route('ai.rule-suggestions.generate'))->assertSessionHasErrors('demo');

    expect($press->fresh()->hasActiveAiConsent())->toBeFalse();
});

it('keeps billing out of reach of the press account', function () {
    $this->actingAs(pressAccount());

    $this->get(route('settings.billing.portal'))
        ->assertRedirect(route('settings.billing'))
        ->assertSessionHasErrors('demo');

    $this->get(route('subscribe.checkout'))->assertForbidden();
});

it('leaves the press account able to log out and edit its own data', function () {
    $press = pressAccount();

    $this->actingAs($press);

    // Logout is a POST: blocking every mutating method wholesale would trap a
    // journalist in the session.
    $this->post(route('logout'))->assertRedirect();

    $this->actingAs($press->fresh());

    $this->post(route('labels.store'), ['name' => 'Viajes', 'color' => 'blue'])
        ->assertSessionHasNoErrors();
});

it('gives the shared accounts a wider MCP rate limit', function () {
    $limiter = RateLimiter::limiter('mcp');

    $request = Request::create('/mcp', 'POST');
    $request->setUserResolver(fn () => pressAccount());

    expect($limiter($request)->maxAttempts)->toBe(600);

    $regular = Request::create('/mcp', 'POST');
    $regular->setUserResolver(fn () => User::factory()->create());

    expect($limiter($regular)->maxAttempts)->toBe(60);
});
