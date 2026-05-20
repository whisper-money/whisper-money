<?php

it('bounds Docker image publishing time', function () {
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain("build-image:\n    runs-on: ubuntu-latest\n    timeout-minutes: 45")
        ->toContain("Build and push development image\n        uses: docker/build-push-action@v6\n        timeout-minutes: 20")
        ->toContain("Build and push production image\n        uses: docker/build-push-action@v6\n        timeout-minutes: 25")
        ->toContain('platforms: linux/amd64')
        ->not->toContain('platforms: linux/amd64,linux/arm64')
        ->not->toContain('docker/setup-qemu-action');
});

it('keeps production Docker dependency layers cacheable', function () {
    $dockerfile = file_get_contents(base_path('Dockerfile.production'));

    expect(strpos($dockerfile, 'COPY composer.json composer.lock ./'))->toBeLessThan(strpos($dockerfile, 'COPY . /app/.'));
    expect(strpos($dockerfile, 'COPY package.json bun.lock ./'))->toBeLessThan(strpos($dockerfile, 'COPY . /app/.'));
    expect(strpos($dockerfile, 'RUN composer install --no-dev --no-scripts'))->toBeLessThan(strpos($dockerfile, 'COPY . /app/.'));
    expect(strpos($dockerfile, 'RUN bun install --frozen-lockfile'))->toBeLessThan(strpos($dockerfile, 'COPY . /app/.'));
});
