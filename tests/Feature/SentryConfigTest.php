<?php

it('binds the sentry release from the environment', function () {
    expect(config('sentry.release'))->toBe(env('SENTRY_RELEASE'));
});

it('waits for the image build before marking a sentry deploy', function () {
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain("deploy:\n    runs-on: ubuntu-latest\n    needs: [build-image]")
        ->toContain('run: sentry-cli releases deploys "$SENTRY_RELEASE" new -e production');
});
