<?php

use App\Services\Stats\BinomialProportion;
use App\Services\Stats\ProportionSignificance;

beforeEach(function () {
    $this->stats = new ProportionSignificance;
});

it('computes the Wilson score interval', function () {
    [$low, $high] = $this->stats->wilsonInterval(6, 52);
    expect($low)->toEqualWithDelta(0.0540, 0.0005)
        ->and($high)->toEqualWithDelta(0.2297, 0.0005);

    [$low2, $high2] = $this->stats->wilsonInterval(5, 179);
    expect($low2)->toEqualWithDelta(0.0120, 0.0005)
        ->and($high2)->toEqualWithDelta(0.0640, 0.0005);
});

it('keeps the Wilson interval inside [0, 1] at the k=0 and k=n boundaries', function () {
    expect($this->stats->wilsonInterval(0, 30)[0])->toBe(0.0)
        ->and($this->stats->wilsonInterval(30, 30)[1])->toBe(1.0);
});

it('computes a two-sided Fisher exact p-value', function () {
    // Real experiment table: reduced 6/52 vs pay_now 5/179.
    expect($this->stats->fisherExactTwoSided(6, 46, 5, 174))->toEqualWithDelta(0.0182, 0.0005);
    // Clean separation 5/5 vs 0/5 → 2 * C(5,5)/C(10,5) = 2/252.
    expect($this->stats->fisherExactTwoSided(5, 0, 0, 5))->toEqualWithDelta(0.007936, 0.00001);
    // Degenerate margin (no successes anywhere) → p = 1.
    expect($this->stats->fisherExactTwoSided(0, 10, 0, 10))->toBe(1.0);
});

it('computes the Newcombe difference interval and the descriptive z', function () {
    $reduced = new BinomialProportion('reduced', 6, 52);
    $payNow = new BinomialProportion('pay_now', 5, 179);

    [$low, $high] = $this->stats->newcombeDiffInterval($reduced, $payNow);
    expect($low)->toEqualWithDelta(0.0164, 0.0005)
        ->and($high)->toEqualWithDelta(0.2029, 0.0005)
        ->and($this->stats->twoProportionZ($reduced, $payNow))->toEqualWithDelta(2.607, 0.01);
});

it('calls the borderline real comparison NOT significant under Bonferroni', function () {
    $result = $this->stats->compare(
        new BinomialProportion('reduced', 6, 52),
        new BinomialProportion('pay_now', 5, 179),
    );

    // Fisher p ≈ 0.018 exceeds the corrected bar 0.05/3 ≈ 0.0167 → not a winner yet.
    expect($result['significant'])->toBeFalse()
        ->and($result['fisherP'])->toEqualWithDelta(0.0182, 0.0005)
        ->and($result['alpha'])->toEqualWithDelta(0.0167, 0.0005)
        ->and($result['minExpectedCount'])->toEqualWithDelta(2.48, 0.05);
});

it('calls a clean separation significant', function () {
    $result = $this->stats->compare(
        new BinomialProportion('a', 5, 5),
        new BinomialProportion('b', 0, 5),
    );

    // Fisher p ≈ 0.008 clears the corrected bar.
    expect($result['significant'])->toBeTrue()
        ->and($result['fisherP'])->toEqualWithDelta(0.007936, 0.00001);
});
