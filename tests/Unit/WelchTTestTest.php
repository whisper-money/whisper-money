<?php

use App\Services\Stats\WelchTTest;

it('returns no difference and p=1 for identical arms', function () {
    $result = (new WelchTTest)->compare(10.0, 4.0, 100, 10.0, 4.0, 100);

    expect($result['diff'])->toBe(0.0)
        ->and($result['t'])->toBe(0.0)
        ->and($result['p'])->toEqualWithDelta(1.0, 1e-6); // erf approximation, |error| < 1.5e-7
});

it('yields a two-sided p of ~0.317 when the difference equals one standard error', function () {
    // varA/nA = varB/nB = 0.5 → seSq = 1, se = 1; diff = 1 → t = 1.
    // Two-sided normal p = 2·(1 − Φ(1)) = 0.3173, validating the erf/normalCdf path.
    $result = (new WelchTTest)->compare(1.0, 50.0, 100, 0.0, 50.0, 100);

    expect($result['t'])->toEqualWithDelta(1.0, 1e-9)
        ->and($result['se'])->toEqualWithDelta(1.0, 1e-9)
        ->and($result['p'])->toEqualWithDelta(0.3173, 1e-3);
});

it('finds a large separation highly significant', function () {
    $result = (new WelchTTest)->compare(10.0, 4.0, 100, 8.0, 4.0, 100);

    expect($result['t'])->toEqualWithDelta(7.071, 1e-2)
        ->and($result['p'])->toBeLessThan(0.001);
});

it('defers (p=1) when an arm has too few observations to estimate variance', function () {
    $result = (new WelchTTest)->compare(5.0, 0.0, 1, 0.0, 0.0, 1);

    expect($result['p'])->toBe(1.0);
});
