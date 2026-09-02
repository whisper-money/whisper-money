<?php

use App\Models\MonthlySummary;
use App\Models\Space;
use App\Models\User;
use App\Services\MonthlySummary\Summaries;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * The cards are drawn on the way out of the send, not while a reader waits.
 *
 * Chromium is faked — writing a file where each job asked for its PNG — because
 * what is under test is that one run draws every card the month can show, and
 * that the months before it stop taking up room.
 *
 * The HTML each job was drawn from is kept as it goes past, which is where the
 * copy inside a card can be read without decoding a picture.
 */

beforeEach(function (): void {
    Storage::fake('public');

    $this->drawnHtml = [];

    Process::fake(function (PendingProcess $process) {
        $manifest = json_decode((string) file_get_contents((string) last($process->command)), true);

        foreach ($manifest as $job) {
            $this->drawnHtml[$job['path']] = (string) file_get_contents($job['html']);
            file_put_contents($job['png'], 'png');
        }

        return Process::result('');
    });
});

/**
 * @return callable(PendingProcess): bool
 */
function ranTheRenderer(): callable
{
    return fn (PendingProcess $process): bool => in_array(base_path('scripts/render-card.mjs'), $process->command, true);
}

function summaryToSend(User $user, string $period, ?Space $space = null): MonthlySummary
{
    return MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => ($space ?? $user->activeSpace())->id,
        'period' => $period,
    ]);
}

it('draws every card the month can show in a single browser run', function (): void {
    $summary = summaryToSend(User::factory()->onboarded()->create(), '2026-08');

    app(Summaries::class)->prepareCards($summary, pro: false);

    // Five cards the full payload can draw, in three formats, light and dark,
    // one Chromium.
    Process::assertRanTimes(ranTheRenderer(), 1);
    expect(Storage::disk('public')->files("monthly-summaries/{$summary->id}"))->toHaveCount(30);
});

it('keeps the theme and the language in the name of the file it caches', function (): void {
    // Neither used to be in the key, so the dark cut overwrote the light one and
    // a Spanish reader was served whichever language happened to be drawn first.
    $summary = summaryToSend(User::factory()->onboarded()->create(), '2026-08');

    app(Summaries::class)->prepareCards($summary, pro: false);

    expect(Storage::disk('public')->files("monthly-summaries/{$summary->id}"))
        ->toContain("monthly-summaries/{$summary->id}/savings_rate-feed-light-en.png")
        ->toContain("monthly-summaries/{$summary->id}/savings_rate-feed-dark-en.png");
});

it('draws a second set for a second language rather than reusing the first', function (): void {
    $summary = summaryToSend(User::factory()->onboarded()->create(), '2026-08');

    app(Summaries::class)->prepareCards($summary, pro: false);
    app()->setLocale('es');
    app(Summaries::class)->prepareCards($summary, pro: false);

    Process::assertRanTimes(ranTheRenderer(), 2);
    expect(Storage::disk('public')->files("monthly-summaries/{$summary->id}"))->toHaveCount(60)
        ->toContain("monthly-summaries/{$summary->id}/savings_rate-feed-light-es.png");
});

it('writes the card in the language the app is speaking', function (): void {
    app()->setLocale('es');
    $summary = summaryToSend(User::factory()->onboarded()->create(), '2026-08');

    app(Summaries::class)->prepareCards($summary, pro: false);

    expect($this->drawnHtml["monthly-summaries/{$summary->id}/savings_rate-feed-light-es.png"])
        ->toContain('Tasa de ahorro')
        ->toContain('lang="es"')
        ->not->toContain('Savings rate');
});

it('flips the spending split\'s shades so the biggest slice is visible on a dark card', function (): void {
    // The ramp is a fixed monochrome scale. Left at its light values, the block
    // for the largest category is the dark card's own background colour.
    $summary = summaryToSend(User::factory()->onboarded()->create(), '2026-08');

    app(Summaries::class)->prepareCards($summary, pro: false);

    // #d4d4d8 belongs to the dark ramp alone and #52525b to the light one, so
    // each card proves it used its own scale and not the other's.
    expect($this->drawnHtml["monthly-summaries/{$summary->id}/spending_split-feed-light-en.png"])
        ->toContain('#52525b')
        ->not->toContain('#d4d4d8')
        ->and($this->drawnHtml["monthly-summaries/{$summary->id}/spending_split-feed-dark-en.png"])
        ->toContain('#d4d4d8')
        ->not->toContain('#52525b');
});

it('gives chromium a writable home', function (): void {
    // Without it the crash handler exits before the browser is usable, and
    // php-fpm hands its workers none of the environment the image sets — so
    // every card drawn inside a web request failed.
    app(Summaries::class)->prepareCards(summaryToSend(User::factory()->onboarded()->create(), '2026-08'), pro: false);

    Process::assertRan(fn (PendingProcess $process): bool => ranTheRenderer()($process)
        && is_writable((string) ($process->environment['HOME'] ?? '')));
});

it('leaves an already rendered card alone', function (): void {
    $summary = summaryToSend(User::factory()->onboarded()->create(), '2026-08');

    app(Summaries::class)->prepareCards($summary, pro: false);
    app(Summaries::class)->prepareCards($summary, pro: false);

    Process::assertRanTimes(ranTheRenderer(), 1);
});

it('throws away the cards of the months before', function (): void {
    $user = User::factory()->onboarded()->create();
    $previous = summaryToSend($user, '2026-07');
    $current = summaryToSend($user, '2026-08');
    $other = summaryToSend(User::factory()->onboarded()->create(), '2026-07');
    $otherSpace = summaryToSend($user, '2026-07', Space::factory()->create());

    foreach ([$previous, $other, $otherSpace] as $summary) {
        Storage::disk('public')->put("monthly-summaries/{$summary->id}/streak-feed-light-en.png", 'png');
    }

    app(Summaries::class)->prepareCards($current, pro: false);

    expect(Storage::disk('public')->exists("monthly-summaries/{$previous->id}/streak-feed-light-en.png"))->toBeFalse()
        ->and(Storage::disk('public')->exists("monthly-summaries/{$other->id}/streak-feed-light-en.png"))->toBeTrue()
        ->and(Storage::disk('public')->exists("monthly-summaries/{$otherSpace->id}/streak-feed-light-en.png"))->toBeTrue()
        ->and(Storage::disk('public')->files("monthly-summaries/{$current->id}"))->toHaveCount(30);
});
