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
            ->has('cards.0.formats', 3)
            // Every card carries the picture the screen paints, not just the links.
            ->where('cards.0.preview', fn (string $url): bool => str_contains($url, 'card/'.$summary->card->value.'/feed?preview=1'))
            ->where('shareUrl', null));
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
