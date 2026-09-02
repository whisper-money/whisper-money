<?php

use App\Enums\MonthlySummaryCard;
use App\Enums\MonthlySummaryFormat;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\CardRenderer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * Chromium is started from two places: a queue worker for the feed card that
 * rides in the email, and a plain web request for the two formats rendered on
 * demand. Only the worker had been given a writable HOME, and without one the
 * browser dies during launch — its crash handler refuses to start without a
 * database directory — so every on-demand card 503'd (PHP-LARAVEL-5M).
 */

function summaryToRender(): MonthlySummary
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    return MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);
}

/**
 * Stand in for the render script, which is expected to leave a PNG behind at
 * the path it was handed — the fourth argument of the command.
 */
function fakeRenderWritingAPng(): void
{
    Process::fake(function (PendingProcess $process) {
        file_put_contents($process->command[3], 'png-bytes');

        return Process::result();
    });
}

it('stores the rendered card on the public disk and reuses it next time', function (): void {
    Storage::fake('public');
    fakeRenderWritingAPng();

    $summary = summaryToRender();
    $renderer = app(CardRenderer::class);

    $path = $renderer->path($summary, MonthlySummaryCard::Streak, MonthlySummaryFormat::Story, pro: false);

    expect(Storage::disk('public')->get($path))->toBe('png-bytes');
    Process::assertRanTimes(fn (): bool => true, 1);

    // Second ask: the file is already there, so nothing is rendered again.
    expect($renderer->path($summary, MonthlySummaryCard::Streak, MonthlySummaryFormat::Story, pro: false))->toBe($path);
    Process::assertRanTimes(fn (): bool => true, 1);
});

it('keeps the Pro badge out of the unbadged card by caching them apart', function (): void {
    Storage::fake('public');
    fakeRenderWritingAPng();

    $summary = summaryToRender();
    $renderer = app(CardRenderer::class);

    $free = $renderer->path($summary, MonthlySummaryCard::Streak, MonthlySummaryFormat::Story, pro: false);
    $pro = $renderer->path($summary, MonthlySummaryCard::Streak, MonthlySummaryFormat::Story, pro: true);

    expect($pro)->not->toBe($free);
    Process::assertRanTimes(fn (): bool => true, 2);
});

it('hands the browser a writable home directory whoever started the render', function (): void {
    Storage::fake('public');
    Process::fake();

    $summary = summaryToRender();

    // A faked process writes no PNG, so the render still fails. What matters
    // here is what reached the process before it did.
    expect(fn (): string => app(CardRenderer::class)->path(
        $summary,
        MonthlySummaryCard::Streak,
        MonthlySummaryFormat::Story,
        pro: false,
    ))->toThrow(RuntimeException::class);

    Process::assertRan(function (PendingProcess $process): bool {
        $home = $process->environment['HOME'] ?? null;

        return is_string($home) && is_dir($home) && is_writable($home);
    });
});

it('says what the renderer printed when it fails', function (): void {
    Storage::fake('public');
    Process::fake([
        '*' => Process::result(errorOutput: 'browser has been closed', exitCode: 1),
    ]);

    expect(fn (): string => app(CardRenderer::class)->path(
        summaryToRender(),
        MonthlySummaryCard::Streak,
        MonthlySummaryFormat::Story,
        pro: false,
    ))->toThrow(RuntimeException::class, 'Card render failed: browser has been closed');
});
