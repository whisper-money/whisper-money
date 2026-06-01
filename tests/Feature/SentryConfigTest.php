<?php

it('binds the sentry release from the environment', function () {
    expect(config('sentry.release'))->toBe(env('SENTRY_RELEASE'));
});

it('disables the sentry dsn outside the production environment', function () {
    expect(app()->environment())->not->toBe('production')
        ->and(config('sentry.dsn'))->toBeNull();
});

it('does not wait for registry image publishing before marking a sentry deploy', function () {
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain("deploy:\n    runs-on: ubuntu-latest\n    needs: [tests, linter, static-analysis, performance-tests, changes]")
        ->toContain('sentry-cli releases new "$SENTRY_RELEASE"')
        ->toContain('run: sentry-cli releases deploys "$SENTRY_RELEASE" new -e production');
});
