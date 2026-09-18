<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Achievement;
use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Achievements\CardRenderer;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

/*
 * The progress screen, in a browser.
 *
 * What the screen is handed is already pinned by tests/Feature/Achievements, so
 * what is asserted here is only what a browser can prove: that an earned medal
 * is drawn with its name and a locked one is not, that the share dialog builds
 * the URL the reader asked for, and that the two things the shell says out loud
 * — the categorize nudge and the medal announcement — appear and stay away.
 *
 * Chromium is never started for a card. The renderer answers with a path and a
 * 1x1 PNG sits at it, so the preview in the dialog paints like the real thing.
 */

beforeEach(function (): void {
    // Not what this screen is about, and the gate itself is covered elsewhere.
    config(['subscriptions.enabled' => false]);

    // A frozen clock: the nudge counts the month in progress, so a run started
    // on the last second of a month must not read a different month by the time
    // the page renders.
    $this->travelTo('2026-03-10 09:00:00');

    stubMedalCards();
});

function stubMedalCards(): void
{
    Storage::fake(CardRenderer::DISK);
    Storage::disk(CardRenderer::DISK)->put('achievements/stub/medal.png', (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ));

    test()->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('path')->andReturn('achievements/stub/medal.png');
    });
}

/**
 * A reader the visit tracker will leave alone.
 *
 * `last_active_at` is now, so the middleware that carries a run forward sees the
 * same day inside its throttle and writes nothing: the streak the test set up is
 * the streak the page draws.
 */
function medalReader(int $streak = 5): User
{
    $user = User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
        'locale' => 'en',
    ]);

    $user->forceFill([
        'last_active_at' => now(),
        'visit_streak' => $streak,
        'longest_visit_streak' => $streak,
    ])->saveQuietly();

    return $user;
}

function medalFor(User $user, string $key, array $attributes = []): Achievement
{
    return Achievement::factory()->key($key)->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        ...$attributes,
    ]);
}

it('names what was earned and the next rung, and keeps the rest to itself', function (): void {
    // Three days of visits against a five-month saving streak: two different
    // runs, counted from two different places, so a screen that read one off
    // the other would print the wrong number somewhere below.
    $user = medalReader(streak: 3);
    medalFor($user, 'hygiene.1');
    medalFor($user, 'net_worth.1');
    // The saving streak in the overview is read off the last report, so the two
    // screens cannot disagree about the same number.
    MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    actingAs($user);

    $page = visit('/progress');

    $page->assertSee('Progress')
        ->assertSee('Every milestone your money has crossed, dated when it actually happened.')
        // The overview: what is on the shelf, the live saving streak, the last one in.
        ->assertSee('Unlocked')
        ->assertSee('of 59')
        ->assertSee('Saving streak')
        ->assertSee('5 months')
        // Earned, so it is named and carries its milestone.
        ->assertSee('First closed month')
        ->assertSee('Net worth')
        // The next rung of a track arrives named: thirteen identical
        // silhouettes say nothing about what there is to chase.
        ->assertSee('First bank connected')
        // Everything past it does not. This one is two rungs along the same
        // track, and its name must not be anywhere on the page.
        ->assertDontSee('3 connected accounts')
        // The road ahead, folded into one slot per track.
        ->assertSee('to come')
        // Every one of the thirteen ladders is drawn.
        ->assertSee('Visit streaks')
        ->assertSee('Data hygiene')
        ->assertSee('Momentum')
        // The pill in the header counts the visit run as it stands today, which
        // is not the saving streak the overview reads off the last report.
        ->assertPresent('button[aria-label="Visit streak: 3 days"]')
        ->assertNoJavascriptErrors();
});

it('shares a medal, with and without the amount on it', function (): void {
    // One medal, so the share button on its cell is the only one on the screen.
    // A money one, because leaving the figure off is the choice this dialog adds.
    $user = medalReader();
    medalFor($user, 'net_worth.1');

    actingAs($user);

    $page = visit('/progress');

    $page->assertSee('Net worth')
        ->click('[aria-label="Share this medal"]')
        ->assertSee('Share this medal')
        ->assertSee('Pick a shape and a skin. Nothing leaves your device until you send it.')
        ->assertSee('Post · 4:5')
        ->assertSee('Story · 9:16')
        // The picture the reader is about to post, drawn from the same URL the
        // share sheet will hand over.
        ->assertPresent('[role="dialog"] img')
        // Saving it is the fallback for a browser whose share sheet cannot
        // carry files, so it is always offered.
        ->assertPresent('[role="dialog"] a[download]')
        ->assertAttributeContains('[role="dialog"] a[download]', 'href', 'amount=1')
        ->assertAttributeContains('[role="dialog"] a[download]', 'download', 'whisper-money-net_worth-1-feed-light.png')
        // Off, the medal still says what it is; the figure stops being a
        // statement about how much money somebody has.
        ->assertSee('Leave the amount off')
        ->click('Leave the amount off')
        ->assertAttributeContains('[role="dialog"] a[download]', 'href', 'amount=0')
        ->assertNoJavascriptErrors();
});

it('asks for the uncategorized month, and stops asking once it is snoozed', function (): void {
    $user = medalReader();

    // The month in progress, which is the pile the nudge is about: the sweep
    // reads closed months, this asks about the one nobody has tidied yet.
    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'account_id' => Account::factory()->create([
            'user_id' => $user->id,
            'currency_code' => 'EUR',
        ])->id,
        'category_id' => null,
        'transaction_date' => now(),
        'currency_code' => 'EUR',
    ]);

    actingAs($user);

    $page = visit('/progress');

    $page->assertSee('2 uncategorized transactions')
        ->assertSee('A month with everything categorized is a month you can actually read.')
        ->assertSee('Categorize')
        ->click('Not now')
        ->assertDontSee('2 uncategorized transactions')
        ->assertNoJavascriptErrors();

    // Kept on the reader rather than in this browser, so saying "not now" on
    // the laptop says it on the phone too.
    $page->refresh()
        ->assertSee('Progress')
        ->assertDontSee('2 uncategorized transactions')
        ->assertNoJavascriptErrors();

    expect($user->fresh()->uncategorized_prompt_snoozed_until?->isFuture())->toBeTrue();
});

it('announces a medal this browser has not been told about', function (): void {
    // Written apart in time on purpose: which medal the shell announces is the
    // last visit one the reader was given, and two rows sharing a timestamp
    // would leave that order to their random ids.
    $user = medalReader();
    medalFor($user, 'visits.1', ['created_at' => now()->subMinutes(2)]);
    medalFor($user, 'visits.2', ['created_at' => now()->subMinute()]);

    actingAs($user);

    $page = visit('/progress');

    // Nothing is announced on a browser that has never been told anything: the
    // shelf may be years old, and greeting it with an old medal would be a lie.
    // This first load only takes note of where the reader stands.
    $page->assertSee('Progress')
        ->assertDontSee('Medal unlocked')
        ->assertNoJavascriptErrors();

    // A third day's medal lands, the way the request that earns it would leave
    // it: on the shelf, with the browser still remembering the second.
    medalFor($user, 'visits.3', ['created_at' => now()]);

    $page->refresh()
        ->assertSee('Medal unlocked')
        ->assertSee('Visit streak')
        ->assertSee('See it')
        ->assertNoJavascriptErrors();
});
