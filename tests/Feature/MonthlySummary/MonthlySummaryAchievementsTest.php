<?php

use App\Mail\Drip\MonthlySummaryEmail;
use App\Models\Achievement;
use App\Models\Category;
use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\CardRenderer;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

/*
 * The medals block of the monthly report.
 *
 * Two halves, in both the email and on the screen: what the reported month
 * earned, and what is close enough now to say how far off it is. Behind the
 * same flag as everything else about medals, and gone entirely when neither
 * half has anything to say.
 */

beforeEach(function (): void {
    Cache::flush();
    config()->set('achievements.enabled', true);
    // These assertions are about props and rendered mail, never about the HTML
    // an SSR pass would produce — see MonthlySummaryPagesTest.
    config()->set('inertia.ssr.enabled', false);

    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('url')->andReturn('https://whisper.money/storage/card.png');
        $mock->shouldReceive('forget')->andReturnNull();
    });
});

function readerWithMedalReport(): User
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    return $user;
}

/**
 * @param  list<string>  $keys
 */
function awardMedals(User $user, array $keys, ?string $on = null): void
{
    foreach ($keys as $key) {
        Achievement::factory()->key($key)->create([
            'user_id' => $user->id,
            'space_id' => $user->activeSpace()->id,
            'achieved_on' => $on ?? now()->subMonths(6)->startOfMonth(),
        ]);
    }
}

function renderedMedalReport(User $user): string
{
    $summary = $user->monthlySummaries()->first();

    return (new MonthlySummaryEmail($user, $summary))->render();
}

it('remembers the medals the reported month earned, and only those', function (): void {
    $user = readerWithMedalReport();
    $month = $user->monthlySummaries()->first()->periodStart();

    awardMedals($user, ['streaks.1', 'hygiene.5', 'visits.1'], $month->toDateString());
    awardMedals($user, ['safety.1'], $month->copy()->subMonth()->toDateString());

    expect(renderedMedalReport($user))
        ->toContain('What you unlocked in '.$month->isoFormat('MMMM'))
        ->toContain('Saving streak')
        ->toContain('3 months')
        // Every figure carries its unit: "Visit streak, 3" is not a milestone.
        ->toContain('3 days')
        // A medal with no figure to it is still worth naming.
        ->toContain('First budget')
        // Earned the month before the one this report is about.
        ->not->toContain('Loan paid off');
});

it('suggests the three nearest medals, nearest first, each with the distance left', function (): void {
    $user = readerWithMedalReport();

    // Everything below these rungs is already earned, so the next one on each
    // track is the one the frozen month can measure a distance to.
    awardMedals($user, [
        'streaks.1',                                                  // next: 6 months, standing at 5
        'savings_rate.1', 'savings_rate.2',                           // next: 50%, standing at 35.5%
        'net_worth.1', 'net_worth.2', 'net_worth.3', 'net_worth.4',   // next: 250,000 €, standing at 160,223.05 €
        'monthly_saving.1', 'monthly_saving.2',                       // next: 2,500 €, standing at 1,368.05 €
    ]);

    $rendered = renderedMedalReport($user);

    expect($rendered)->toContain('What you can unlock next');

    $order = collect(['Saving streak', 'Savings rate', 'Net worth', 'Monthly saving'])
        ->mapWithKeys(fn (string $name): array => [$name => strpos($rendered, $name.'</strong>, ')])
        ->filter(fn (int|bool $at): bool => $at !== false);

    // Three at most, nearest first: 83% of the way there, then 71%, then 64%.
    // The fourth, at 55%, does not make the cut.
    expect($order->keys()->all())->toBe(['Saving streak', 'Savings rate', 'Net worth'])
        ->and($order->values()->all())->toBe($order->values()->sort()->values()->all())
        // A month of the streak left, and the amount left of the net worth rung.
        ->and($rendered)->toContain('<strong>1 month</strong> to go')
        ->and($rendered)->toContain('89,776.95');
});

it('says nothing at all when the medals are off for the reader', function (): void {
    config()->set('achievements.enabled', false);

    $user = readerWithMedalReport();
    awardMedals($user, ['streaks.1'], $user->monthlySummaries()->first()->periodStart()->toDateString());

    expect(renderedMedalReport($user))
        ->not->toContain('What you unlocked')
        ->not->toContain('What you can unlock next');

    $this->actingAs($user)
        ->get(route('monthly-summaries.show', $user->monthlySummaries()->first()))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('achievements', null));
});

it('drops the block when the month earned nothing and every next medal is already within reach', function (): void {
    $user = readerWithMedalReport();

    // Standing past every rung the report can measure: those medals land on
    // tonight's sweep, so there is nothing to chase and nothing to remember.
    $user->forceFill(['longest_visit_streak' => 400, 'longest_visit_week_streak' => 60])->save();

    Transaction::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->activeSpace()->id])->id,
        'transaction_date' => now()->subMonth()->startOfMonth()->addDays(3),
    ]);

    expect(renderedMedalReport($user))
        ->not->toContain('What you unlocked')
        ->not->toContain('What you can unlock next');
});

it('hands the screen the same block it puts in the email', function (): void {
    $user = readerWithMedalReport();
    $summary = $user->monthlySummaries()->first();

    awardMedals($user, ['streaks.1'], $summary->periodStart()->toDateString());

    $this->actingAs($user)
        ->get(route('monthly-summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('achievements', 2)
            ->where('achievements.0.title', 'What you unlocked in '.$summary->periodStart()->isoFormat('MMMM'))
            ->has('achievements.0.lines', 1)
            ->where('achievements.1.title', 'What you can unlock next'));
});
