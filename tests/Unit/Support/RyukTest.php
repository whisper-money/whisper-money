<?php

use Tests\Support\Ryuk;

// Ryuk parses the filter as a query string: an unencoded "=" would make it reap by
// a label nothing carries, and every container would leak silently.
it('url-encodes the session filter', function (): void {
    expect(Ryuk::filter('4242-a1b2c3d4'))
        ->toBe('label=money.whisper.test-session%3D4242-a1b2c3d4');
});
