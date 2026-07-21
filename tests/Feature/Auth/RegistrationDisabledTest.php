<?php

use Illuminate\Support\Env;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

/**
 * Force REGISTRATION_ENABLED for the current process. The framework loads .env
 * immutably, so a plain putenv()/$_ENV write is ignored once the key is present.
 * Clearing the repository entry first lets us override it deterministically.
 */
function setRegistrationEnabledEnv(?string $value): void
{
    $repository = Env::getRepository();
    $repository->clear('REGISTRATION_ENABLED');

    if ($value !== null) {
        $repository->set('REGISTRATION_ENABLED', $value);
    }
}

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

afterEach(function () {
    // Clear the override so sibling tests in the same worker fall back to the
    // default (registration enabled).
    setRegistrationEnabledEnv(null);
});

test('registration feature is enabled by default', function () {
    expect(config('fortify.features'))->toContain(Features::registration());
});

test('registration feature is removed when REGISTRATION_ENABLED is false', function () {
    setRegistrationEnabledEnv('false');

    $features = (require base_path('config/fortify.php'))['features'];

    expect($features)
        ->not->toContain(Features::registration())
        ->and($features)->toContain(Features::resetPasswords())
        ->and($features)->toContain(Features::emailVerification());
});

test('the landing page advertises registration by default', function () {
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', true)
        );
});

test('the landing page hides registration when the feature is disabled', function () {
    config(['fortify.features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
    ]]);

    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('canRegister', false)
        );
});

test('the login page hides the sign-up link when registration is disabled', function () {
    config(['fortify.features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
    ]]);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('canRegister', false)
        );
});

test('the register routes are not registered when registration is disabled', function () {
    setRegistrationEnabledEnv('false');

    $this->refreshApplication();

    expect(app('router')->has('register'))->toBeFalse()
        ->and(app('router')->has('register.store'))->toBeFalse();

    $this->withoutVite()->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->withoutVite()->get(route('login'))->assertSuccessful();
});
