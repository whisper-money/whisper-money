<?php

use App\Services\Cards\CardBrowser;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * The half of the card pipeline that drives the browser.
 *
 * The environment it hands the subprocess is the whole subject here, because
 * getting it wrong is invisible until somebody tries to draw a card: HOME has
 * to be writable or Chromium's crash handler kills the browser, and HOME has to
 * be left alone wherever it already is or Playwright cannot find the browser it
 * installed under it. Moving it unconditionally is what stopped every card
 * rendering on a developer's machine while production stayed fine, because the
 * production image pins PLAYWRIGHT_BROWSERS_PATH and does not need HOME to
 * point anywhere in particular.
 */

beforeEach(function (): void {
    Storage::fake('local');

    Process::fake(function (PendingProcess $process) {
        $manifest = json_decode((string) file_get_contents((string) last($process->command)), true);

        foreach ($manifest as $page) {
            file_put_contents($page['png'], 'png');
        }

        return Process::result('');
    });
});

function shootOne(string $disk = 'local'): void
{
    $browser = app(CardBrowser::class);

    $browser->shoot([$browser->page($disk, 'cards/one.png', '<p>one</p>', 1080, 1350)]);
}

/** The environment the one render in this test was given. */
function renderEnvironment(): array
{
    $seen = [];

    Process::assertRan(function (PendingProcess $process) use (&$seen): bool {
        $seen = $process->environment;

        return true;
    });

    return $seen;
}

it('leaves HOME alone when the one it has is writable', function (): void {
    // A developer's machine, and the queue worker in the production image:
    // Playwright's browsers live under this HOME and must stay findable.
    shootOne();

    expect(renderEnvironment())->not->toHaveKey('HOME');
});

it('moves HOME somewhere writable when it cannot be written to', function (): void {
    // php-fpm, whose workers are handed no usable HOME: without one Chromium
    // fails with `chrome_crashpad_handler: --database is required`, then SIGTRAP.
    putenv('HOME=/this/does/not/exist');

    try {
        shootOne();

        expect(renderEnvironment())->toHaveKey('HOME', sys_get_temp_dir());
    } finally {
        putenv('HOME='.($_SERVER['HOME'] ?? '/tmp'));
    }
});

it('stores each page on the disk it was drawn for', function (): void {
    shootOne();

    expect(Storage::disk('local')->exists('cards/one.png'))->toBeTrue();
});

it('draws nothing and asks for no browser when there are no pages', function (): void {
    app(CardBrowser::class)->shoot([]);

    Process::assertNothingRan();
});

it('clears up the scratch files it wrote', function (): void {
    $browser = app(CardBrowser::class);
    $page = $browser->page('local', 'cards/one.png', '<p>one</p>', 1080, 1350);

    $browser->shoot([$page]);

    expect(is_file($page['html']))->toBeFalse();
    expect(is_file($page['png']))->toBeFalse();
});

it('says how many cards it could not draw', function (): void {
    // A browser that came back without writing its PNG: the batch keeps what it
    // managed, and what it missed is what the exception is about.
    Process::fake(fn (): mixed => Process::result('', 'chromium died'));

    expect(fn () => shootOne())
        ->toThrow(RuntimeException::class, 'Card render failed for 1 of 1 card(s): chromium died');
});
