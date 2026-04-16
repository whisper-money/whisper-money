<?php

it('binds the sentry release from the environment', function () {
    expect(config('sentry.release'))->toBe(env('SENTRY_RELEASE'));
});
