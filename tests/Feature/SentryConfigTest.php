<?php

it('binds the sentry release from the environment', function () {
    expect(config('sentry.release'))->toBe(env('SENTRY_RELEASE'));
});

it('disables the sentry dsn outside the production environment', function () {
    expect(app()->environment())->not->toBe('production')
        ->and(config('sentry.dsn'))->toBeNull();
});
