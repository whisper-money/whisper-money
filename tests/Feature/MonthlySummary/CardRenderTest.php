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
 */

beforeEach(function (): void {
    Storage::fake('public');

    Process::fake(function (PendingProcess $process) {
        $manifest = json_decode((string) file_get_contents((string) last($process->command)), true);

        foreach ($manifest as $job) {
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

    // Five cards the full payload can draw, in three formats, one Chromium.
    Process::assertRanTimes(ranTheRenderer(), 1);
    expect(Storage::disk('public')->files("monthly-summaries/{$summary->id}"))->toHaveCount(15);
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
        Storage::disk('public')->put("monthly-summaries/{$summary->id}/streak-feed.png", 'png');
    }

    app(Summaries::class)->prepareCards($current, pro: false);

    expect(Storage::disk('public')->exists("monthly-summaries/{$previous->id}/streak-feed.png"))->toBeFalse()
        ->and(Storage::disk('public')->exists("monthly-summaries/{$other->id}/streak-feed.png"))->toBeTrue()
        ->and(Storage::disk('public')->exists("monthly-summaries/{$otherSpace->id}/streak-feed.png"))->toBeTrue()
        ->and(Storage::disk('public')->files("monthly-summaries/{$current->id}"))->toHaveCount(15);
});
