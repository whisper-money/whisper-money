<?php

use App\Services\Stats\SampleRatioMismatch;

it('reports p=1 for an exactly even split', function () {
    $result = (new SampleRatioMismatch)->evenSplit(50, 50);

    expect($result['chiSq'])->toBe(0.0)
        ->and($result['p'])->toEqualWithDelta(1.0, 1e-6);
});

it('returns a neutral result for empty arms', function () {
    $result = (new SampleRatioMismatch)->evenSplit(0, 0);

    expect($result['chiSq'])->toBe(0.0)
        ->and($result['p'])->toBe(1.0);
});

it('matches the known chi-square for a 60/40 split of 100', function () {
    // χ² = ((60−50)² + (40−50)²)/50 = 4 → p = 2·(1 − Φ(2)) = 0.0455.
    $result = (new SampleRatioMismatch)->evenSplit(60, 40);

    expect($result['chiSq'])->toEqualWithDelta(4.0, 1e-9)
        ->and($result['p'])->toEqualWithDelta(0.0455, 1e-3);
});

it('flags a badly lopsided split with a tiny p', function () {
    $result = (new SampleRatioMismatch)->evenSplit(90, 10);

    expect($result['chiSq'])->toEqualWithDelta(64.0, 1e-9)
        ->and($result['p'])->toBeLessThan(0.001);
});
