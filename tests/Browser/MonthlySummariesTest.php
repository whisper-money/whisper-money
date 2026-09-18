<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\CardRenderer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/*
 * The monthly report, in a browser.
 *
 * The HTTP side of these screens is already pinned by
 * tests/Feature/MonthlySummary, so what is asserted here is only what a browser
 * can prove: that the frozen figures reach the page as sentences a reader can
 * read, that the public link works for somebody who is not logged in, and that
 * the notice on the dashboard stays put away after a reload.
 *
 * Chromium is never started for a card. The renderer answers with a path and a
 * 1x1 PNG sits at it, so the previews paint like the real thing without drawing
 * thirty pictures nobody looks at.
 */

beforeEach(function (): void {
    // Not what these screens are about, and the gate itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    // A frozen clock, so every month label below is the same one on every run
    // and on any day of the month. February 2026 is the month that closed.
    $this->travelTo('2026-03-10 09:00:00');

    stubSummaryCards();
});

function stubSummaryCards(): void
{
    Storage::fake('public');
    Storage::disk('public')->put('monthly-summaries/stub/card.png', (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));

    test()->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('path')->andReturn('monthly-summaries/stub/card.png');
        // Relative on purpose: the public page prints this into an <img>, and a
        // real hostname would send the browser out to the internet mid-test.
        $mock->shouldReceive('url')->andReturn('/storage/monthly-summaries/stub/card.png');
        $mock->shouldReceive('forget')->andReturnNull();
    });
}

/**
 * A reader whose February closed, with the figures the factory freezes.
 */
function summaryReader(): User
{
    return User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
        'locale' => 'en',
    ]);
}

function sentSummaryFor(User $user, ?string $period = null): MonthlySummary
{
    return MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        ...($period === null ? [] : ['period' => $period]),
    ]);
}

it('lists the months that were sent, newest first, and nothing that was not', function (): void {
    $user = summaryReader();
    sentSummaryFor($user);
    // A month some accounts had not reported when it was worked out. It is
    // still listed, and says so, rather than passing itself off as whole.
    sentSummaryFor($user, '2026-01')->forceFill(['complete' => false])->save();
    // Drafted but never sent: a month the reader was never told about has no
    // row here, however complete its figures are.
    MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'period' => '2025-12',
    ]);

    actingAs($user);

    $page = visit('/summaries');

    $page->assertSee('Monthly summaries')
        ->assertSee('February 2026')
        ->assertSee('January 2026')
        ->assertDontSee('December 2025')
        // Newest first: the order is the whole of what the list says.
        ->assertSeeIn('[data-testid="summary-row"]:nth-child(1)', 'February 2026')
        ->assertSeeIn('[data-testid="summary-row"]:nth-child(2)', 'partial month')
        ->assertNoJavascriptErrors();
});

it('reads one month back as the sentences it was sent with', function (): void {
    $user = summaryReader();
    $summary = sentSummaryFor($user);

    // A medal dated to the month that closed, so the report's own achievements
    // section has something to say.
    Achievement::factory()->key('net_worth.1')->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'achieved_on' => '2026-02-01',
    ]);

    actingAs($user);

    $page = visit("/summaries/{$summary->id}");

    $page->assertSee('February 2026')
        // Headline and lede, off the frozen cashflow.
        ->assertSee('You saved 35.5% of what you earned in February.')
        ->assertSee('5 months in a row in the black, and the best of them.')
        // One row per figure, each comparing against the month before.
        ->assertSee('Your net worth is €160,223.05, €3,165.00 more than when January closed.')
        ->assertSee('That saving is €1,368.05 of the €3,850.00 that came in.')
        ->assertSee('Three categories took €1,733.55 of the €2,481.95 you spent: Housing, Groceries and Restaurants.')
        ->assertSee('Your investment accounts hold €8,240.00 in gains over what you put in.')
        ->assertSee('You met 4 of 6 budgets. You went over on Groceries (+€82.40) and Leisure (+€31.10).')
        ->assertSee('Trip to Japan is at 62.0%: €3,100.00 of the €5,000.00 you set.')
        // The actionable half.
        ->assertSee('Worth five minutes')
        ->assertSee('12 transactions in February have no category, €214.80 in total.')
        ->assertSee('Your access to BBVA expires in 6 days.')
        // The medals the month earned, and the way through to the rest of them.
        ->assertSee('What you unlocked in February')
        ->assertSee('Net worth, €10,000.00.')
        ->assertSee('See all your medals')
        // Every card the month can produce, the chosen one among them.
        ->assertSee('Share your month')
        ->assertSee('Savings rate')
        ->assertSee('Where it went')
        ->assertCount('img[alt="Savings rate"]', 1)
        ->assertNoJavascriptErrors();
});

it('prints the analysis when there is one, and no empty block when there is not', function (): void {
    $user = summaryReader();
    $summary = sentSummaryFor($user);
    $summary->forceFill([
        'ai_analysis' => "Restaurants fell because you ate in.\n\nHousing is the one to watch next month.",
        'ai_generated_at' => now(),
    ])->save();

    actingAs($user);

    $page = visit("/summaries/{$summary->id}");

    $page->assertSee('Why this happened')
        ->assertSee('Restaurants fell because you ate in.')
        // Blank lines are paragraphs, not one run-on line.
        ->assertSee('Housing is the one to watch next month.')
        ->assertNoJavascriptErrors();

    // The month whose analysis was never written keeps its figures and drops the
    // block entirely, rather than heading an empty one.
    $without = sentSummaryFor($user, '2026-01');

    $second = visit("/summaries/{$without->id}");

    $second->assertSee('January 2026')
        ->assertSee('The figures')
        ->assertDontSee('Why this happened')
        ->assertNoJavascriptErrors();
});

it('mints a public link, serves it to a stranger, and stops once it is revoked', function (): void {
    $user = summaryReader();
    $summary = sentSummaryFor($user);

    actingAs($user);

    $page = visit("/summaries/{$summary->id}");

    // No URL exists until the reader asks for one.
    $page->assertSee('Create a public link')
        ->click('Create a public link')
        ->assertSee('Revoke the link')
        ->assertSee('Copy')
        ->assertNoJavascriptErrors();

    $token = $summary->fresh()->share_token;
    expect($token)->not->toBeNull();

    // Nobody is logged in from here: the whole point of the link is that it
    // works for somebody with no account.
    Auth::forgetGuards();

    $shared = visit("/s/{$token}");

    $shared->assertSee('Track your own money')
        // The card is what unfurls, and nothing about the reader goes with it.
        ->assertSourceHas('monthly-summaries/stub/card.png')
        ->assertDontSee('160,223')
        ->assertNoJavascriptErrors();

    actingAs($user);

    $back = visit("/summaries/{$summary->id}");

    $back->click('Revoke the link')
        ->assertSee('Create a public link')
        ->assertNoJavascriptErrors();

    expect($summary->fresh()->share_token)->toBeNull();

    Auth::forgetGuards();

    visit("/s/{$token}")
        ->assertSee('404')
        ->assertDontSee('Track your own money')
        ->assertNoJavascriptErrors();
});

it('opens the share dialog on one of the month\'s cards', function (): void {
    $user = summaryReader();
    $summary = sentSummaryFor($user);

    actingAs($user);

    $page = visit("/summaries/{$summary->id}");

    // Five cards, five identical buttons: the first tile is as good as any, and
    // what is under test is the dialog behind them.
    $page->assertSee('Share your month')
        ->click(':nth-match(button:has-text("Share"), 1)')
        ->assertSee('Pick a shape and a skin. Nothing leaves your device until you send it.')
        ->assertSee('Post · 4:5')
        ->assertSee('Story · 9:16')
        ->assertSee('Light')
        ->assertSee('Dark')
        // Saving the picture is the fallback for a browser whose share sheet
        // cannot carry files, so it can never be the thing that is missing.
        ->assertPresent('[role="dialog"] a[download]')
        ->assertAttributeContains('[role="dialog"] a[download]', 'href', '/feed/light')
        ->assertNoJavascriptErrors();
});

it('puts the dashboard notice away for good', function (): void {
    $user = summaryReader();
    $summary = sentSummaryFor($user);

    actingAs($user);

    $page = visit('/dashboard');

    // The notice rides the dashboard's follow-up request, so it arrives after
    // the first paint rather than in it.
    $page->assertSee('Your February summary is ready')
        ->assertSee('You saved 35.5% of what you earned in February.')
        ->click('[aria-label="Dismiss"]')
        ->assertDontSee('Your February summary is ready')
        ->assertNoJavascriptErrors();

    // Stored on the summary rather than in this browser, so it is still away
    // after a reload — and would be on any other device too.
    $page->refresh()
        ->assertSee('Dashboard')
        ->assertDontSee('Your February summary is ready')
        ->assertNoJavascriptErrors();

    expect($summary->fresh()->dismissed_at)->not->toBeNull();
});

it('walks from the dashboard notice into the month and back to the history', function (): void {
    $user = summaryReader();
    sentSummaryFor($user);

    actingAs($user);

    $page = visit('/dashboard');

    $page->assertSee('Your February summary is ready')
        ->click('Open it')
        ->assertSee('The figures')
        ->assertPathBeginsWith('/summaries/')
        ->assertSee('You saved 35.5% of what you earned in February.')
        // The breadcrumb is the way back out to every other month.
        ->click('Summaries')
        ->assertSee('Monthly summaries')
        ->assertPathIs('/summaries')
        ->assertSee('February 2026')
        ->assertNoJavascriptErrors();
});
