<?php

use App\Jobs\Drip\SendMonthlySummaryEmailJob;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Notifications\MonthlySummaryReady;
use App\Services\MonthlySummary\CardRenderer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

/*
 * The bell: one table for everything that happens in an account, a badge and a
 * panel on every page, and a full page behind it. The monthly summary is its
 * first tenant.
 */

beforeEach(function (): void {
    // See AchievementScreenTest: these are about props, not about a
    // server-side render, and SSR would post them to a dev server.
    config()->set('inertia.ssr.enabled', false);

    // The bell is about rows, not pictures.
    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('warm')->andReturnNull();
        $mock->shouldReceive('forgetBefore')->andReturnNull();
        $mock->shouldReceive('url')->andReturn('https://whisper.money/storage/card.png');
        $mock->shouldReceive('path')->andReturn('monthly-summaries/x/card.png');
        $mock->shouldReceive('forget')->andReturnNull();
    });
});

function readerWithReport(): array
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    $summary = MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    return [$user, $summary];
}

function ringBell(User $user, MonthlySummary $summary): void
{
    $user->notify(new MonthlySummaryReady($summary));
}

it('rings the bell once when the report goes out, however often the send is retried', function (): void {
    Queue::fake();
    [$user, $summary] = readerWithReport();
    $summary->forceFill(['sent_at' => null])->save();

    (new SendMonthlySummaryEmailJob($user, $summary))->handle();
    // A worker that died between ringing and stamping `sent_at` retries with the
    // stamp still missing; the row already there is what stops a second ring.
    $summary->forceFill(['sent_at' => null])->save();
    (new SendMonthlySummaryEmailJob($user, $summary->fresh()))->handle();

    expect($user->notifications()->count())->toBe(1);

    $row = $user->notifications()->first();

    expect($row->type)->toBe(MonthlySummaryReady::class)
        ->and($row->data['summary_id'])->toBe($summary->id)
        ->and($row->data['period'])->toBe($summary->period)
        ->and($row->data['headline'])->toBeString()->not->toBeEmpty()
        ->and($row->read_at)->toBeNull();
});

it('shares the badge and the latest rows with every page', function (): void {
    [$user, $summary] = readerWithReport();
    ringBell($user, $summary);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.unread', 1)
            ->has('notifications.recent', 1)
            ->where('notifications.recent.0.kind', 'monthly_summary')
            ->where('notifications.recent.0.url', route('monthly-summaries.show', $summary))
            ->where('notifications.recent.0.read_at', null)
            ->where('notifications.recent.0.body', $user->notifications()->first()->data['headline']));
});

it('names the month in the reader\'s language', function (): void {
    [$user, $summary] = readerWithReport();
    $user->forceFill(['locale' => 'es'])->save();
    $summary->forceFill(['period' => '2026-08'])->save();
    ringBell($user, $summary);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.recent.0.title', 'Tu resumen de agosto está listo'));
});

it('keeps the bell six rows deep and counts the rest in the badge', function (): void {
    [$user, $summary] = readerWithReport();

    foreach (range(1, 7) as $ignored) {
        ringBell($user, $summary);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('notifications.unread', 7)
            ->has('notifications.recent', 6));
});

it('answers outside the paywall, because the bell is drawn outside it too', function (): void {
    foreach (['notifications.list', 'notifications.read', 'notifications.read-all'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())
            ->toContain('onboarded')
            ->not->toContain('subscribed');
    }
});

it('lists everything on its own page', function (): void {
    [$user, $summary] = readerWithReport();
    ringBell($user, $summary);

    $this->actingAs($user)
        ->get(route('notifications.list'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('notifications/index')
            ->has('notifications', 1)
            ->where('notifications.0.kind', 'monthly_summary'));
});

it('opening a row marks it read, puts the notice away and lands on the report', function (): void {
    [$user, $summary] = readerWithReport();
    ringBell($user, $summary);
    $row = $user->notifications()->first();

    $this->actingAs($user)
        ->get(route('notifications.read', $row->id))
        ->assertRedirect(route('monthly-summaries.show', $summary));

    expect($row->fresh()->read_at)->not->toBeNull()
        ->and($summary->fresh()->dismissed_at)->not->toBeNull();
});

it('marks everything read in one go without a redirect', function (): void {
    [$user, $summary] = readerWithReport();
    ringBell($user, $summary);
    ringBell($user, $summary);

    $this->actingAs($user)
        ->post(route('notifications.read-all'))
        ->assertNoContent();

    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($summary->fresh()->dismissed_at)->not->toBeNull();
});

it('reading the report marks its row read', function (): void {
    [$user, $summary] = readerWithReport();
    ringBell($user, $summary);

    $this->actingAs($user)->get(route('monthly-summaries.show', $summary))->assertOk();

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('dismissing the notice marks its row read', function (): void {
    [$user, $summary] = readerWithReport();
    ringBell($user, $summary);

    $this->actingAs($user)->post(route('monthly-summaries.dismiss', $summary))->assertNoContent();

    expect($user->unreadNotifications()->count())->toBe(0);
});

it('will not open another reader\'s row', function (): void {
    [$user, $summary] = readerWithReport();
    ringBell($user, $summary);
    $row = $user->notifications()->first();

    $this->actingAs(User::factory()->onboarded()->create())
        ->get(route('notifications.read', $row->id))
        ->assertNotFound();

    expect($row->fresh()->read_at)->toBeNull();
});

it('stays out of the way when switched off', function (): void {
    config()->set('notifications.enabled', false);
    [$user, $summary] = readerWithReport();
    ringBell($user, $summary);

    $this->actingAs($user)->get(route('notifications.list'))->assertNotFound();
    $this->actingAs($user)->post(route('notifications.read-all'))->assertNotFound();
    $this->actingAs($user)->get(route('notifications.read', $user->notifications()->first()->id))->assertNotFound();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('notifications', null));
});

it('shows guests and readers still onboarding no bell', function (): void {
    $this->actingAs(User::factory()->notOnboarded()->create())
        ->get(route('onboarding'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('notifications', null));
});
