<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SES allows this account 10 sends per second and throttles above it, and every
 * mailable on the `emails` queue goes through this limiter. A bump past the SES
 * ceiling fails here rather than in production halfway through a campaign.
 */
it('keeps the emails queue at the SES send rate', function () {
    $limit = RateLimiter::limiter('emails')(new stdClass);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->decaySeconds)->toBe(1)
        ->and($limit->maxAttempts)->toBe(10);
});
