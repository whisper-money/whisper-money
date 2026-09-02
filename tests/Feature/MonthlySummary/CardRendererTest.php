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
