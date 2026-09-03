<?php

use App\Features\MonthlySummaries;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\CardRenderer;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

/*
 * The history screen and the dashboard notice.
 *
 * The email is one way in, not the only one: a reader who never opens email
 * still gets the report, and a report should not stop existing when the message
 * is archived.
 */

beforeEach(function (): void {
    Feature::define(MonthlySummaries::class, fn (): bool => true);

    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('url')->andReturn('https://whisper.money/storage/card.png');
        $mock->shouldReceive('forget')->andReturnNull();
    });
});

function readerWithSummary(): User
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    return $user;
}

it('lists the months that have been sent', function (): void {
    $user = readerWithSummary();

    $this->actingAs($user)
        ->get('/summaries')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monthly-summaries/index')
            ->has('summaries', 1)
            ->where('summaries.0.period', now()->subMonth()->format('Y-m')));
});

it('leaves out a month that has not been sent yet', function (): void {
    $user = User::factory()->onboarded()->create();
    MonthlySummary::factory()->create(['user_id' => $user->id, 'space_id' => $user->activeSpace()->id]);

    $this->actingAs($user)
        ->get('/summaries')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('summaries', 0));
});

it('shows one month with its figures and every card it can produce', function (): void {
    $user = readerWithSummary();
    $summary = $user->monthlySummaries()->first();

    $this->actingAs($user)
        ->get("/summaries/{$summary->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('monthly-summaries/show')
            ->has('report.rows')
            ->has('cards')
            ->where('cards.0.chosen', true)
            // Both themes, so the screen's light/dark switch flips every preview
            // and every download link without a round trip.
            ->has('cards.0.themes.light.formats', 3)
            ->has('cards.0.themes.dark.formats', 3)
            // Every card carries the picture the screen paints, not just the links.
            ->where('cards.0.themes.light.preview', fn (string $url): bool => str_contains($url, 'card/'.$summary->card->value.'/feed/light?preview=1'))
            ->where('cards.0.themes.dark.preview', fn (string $url): bool => str_contains($url, 'card/'.$summary->card->value.'/feed/dark?preview=1'))
            ->where('shareUrl', null));
});

it('titles the month, because the label is read as one', function (): void {
    // Spanish and French print month names in lower case, and this label is not
    // read inside a sentence: it is the breadcrumb and the browser tab.
    $this->travelTo('2026-03-10');

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'es']);
    $summary = MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    $this->actingAs($user)
        ->get("/summaries/{$summary->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('report.monthLabel', 'Febrero 2026')
            // The one that goes inside a sentence stays as the language writes it.
            ->where('report.monthName', 'febrero'));
});

it('hides the screens entirely while the feature is off', function (): void {
    Feature::define(MonthlySummaries::class, fn (): bool => false);
    $user = readerWithSummary();

    $this->actingAs($user)->get('/summaries')->assertNotFound();
});

it('will not show one reader another reader\'s month', function (): void {
    $summary = MonthlySummary::query()->where('user_id', readerWithSummary()->id)->first();

    $this->actingAs(User::factory()->onboarded()->create())
        ->get("/summaries/{$summary->id}")
        ->assertNotFound();
});

/**
 * The notice is a deferred prop, so it arrives on the dashboard's follow-up
 * request rather than in the first response.
 */
function dashboardNotice(): ?array
{
    return test()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'monthlySummary',
    ])->assertOk()->json('props.monthlySummary');
}

it('offers the newest summary on the dashboard', function (): void {
    $this->actingAs(readerWithSummary())->withoutVite();

    expect(dashboardNotice())
        ->not->toBeNull()
        ->and(dashboardNotice()['monthLabel'])->toBe(now()->subMonth()->locale('en')->isoFormat('MMMM'));
});

it('offers nothing on the dashboard while the feature is off', function (): void {
    Feature::define(MonthlySummaries::class, fn (): bool => false);
    $this->actingAs(readerWithSummary())->withoutVite();

    expect(dashboardNotice())->toBeNull();
});

it('does not run its queries on the first paint', function (): void {
    // Deferred deliberately: a notice is not worth two queries before anything
    // is on screen, and the dashboard has a query ceiling it has to stay under.
    $this->actingAs(readerWithSummary())->withoutVite();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('monthlySummary'));
});

it('shows one notice at most, for the newest month, however many are waiting', function (): void {
    $user = readerWithSummary();
    MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'period' => now()->subMonths(2)->format('Y-m'),
    ]);

    $this->actingAs($user)->withoutVite();

    expect(dashboardNotice()['monthLabel'])->toBe(now()->subMonth()->locale('en')->isoFormat('MMMM'));
});

it('puts the notice away for good once dismissed', function (): void {
    $user = readerWithSummary();
    $summary = $user->monthlySummaries()->first();

    $this->actingAs($user)->withoutVite();
    $this->post("/summaries/{$summary->id}/dismiss")->assertNoContent();

    expect($summary->fresh()->dismissed_at)->not->toBeNull()
        ->and(dashboardNotice())->toBeNull();
});

it('does not fall back to an older month once the newest is dismissed', function (): void {
    // "Your February summary is ready" in April reads as a bug, not a reminder.
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
    $space = $user->activeSpace()->id;
    MonthlySummary::factory()->sent()->dismissed()->create(['user_id' => $user->id, 'space_id' => $space]);
    MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $space,
        'period' => now()->subMonths(2)->format('Y-m'),
    ]);

    $this->actingAs($user)->withoutVite();

    expect(dashboardNotice())->toBeNull();
});

it('will not let one reader dismiss another reader\'s notice', function (): void {
    $summary = MonthlySummary::query()->where('user_id', readerWithSummary()->id)->first();

    $this->actingAs(User::factory()->onboarded()->create())
        ->post("/summaries/{$summary->id}/dismiss")
        ->assertNotFound();

    expect($summary->fresh()->dismissed_at)->toBeNull();
});

it('offers the next month again after the previous one was dismissed', function (): void {
    // The dismissal belongs to one summary, not to the reader: closing August
    // must not silence September.
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
    $space = $user->activeSpace()->id;
    MonthlySummary::factory()->sent()->dismissed()->create([
        'user_id' => $user->id,
        'space_id' => $space,
        'period' => now()->subMonths(2)->format('Y-m'),
    ]);
    MonthlySummary::factory()->sent()->create(['user_id' => $user->id, 'space_id' => $space]);

    $this->actingAs($user)->withoutVite();

    expect(dashboardNotice()['monthLabel'])->toBe(now()->subMonth()->locale('en')->isoFormat('MMMM'));
});
