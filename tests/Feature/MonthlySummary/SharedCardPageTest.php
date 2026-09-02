<?php

use App\Enums\MonthlySummaryFormat;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\CardRenderer;
use Illuminate\Support\Facades\Storage;

/*
 * The public page a shared card unfurls from.
 *
 * The whole point of the URL is that it does not exist until the owner asks for
 * it, and that when it does it carries the picture and nothing else. The renderer
 * is stubbed: what is under test is the access rules, not Chromium.
 */

beforeEach(function (): void {
    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('url')->andReturn('https://whisper.money/storage/card.png');
        $mock->shouldReceive('path')->andReturn('monthly-summaries/x/card.png');
        $mock->shouldReceive('forget')->andReturnNull();
    });
});

function summaryFor(User $user): MonthlySummary
{
    return MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);
}

it('has no public URL until the owner makes one', function (): void {
    $summary = summaryFor(User::factory()->onboarded()->create());

    expect($summary->share_token)->toBeNull();

    $this->get('/s/'.str_repeat('a', 48))->assertNotFound();
});

it('serves the card and a way back, and asks not to be indexed', function (): void {
    $summary = summaryFor(User::factory()->onboarded()->create());
    $token = $summary->mintShareToken();

    $this->get("/s/{$token}")
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('og:image', false)
        ->assertSee('https://whisper.money/storage/card.png', false)
        ->assertSee('whisper.money', false);
});

it('shows no amount, no name and no breakdown', function (): void {
    $user = User::factory()->onboarded()->create(['name' => 'Aurora Nakamura']);
    $summary = summaryFor($user);

    $response = $this->get('/s/'.$summary->mintShareToken());

    $response->assertDontSee('Aurora Nakamura', false)
        ->assertDontSee('160,223', false)
        ->assertDontSee('Trip to Japan', false);
});

it('stops resolving once the link is revoked', function (): void {
    $summary = summaryFor(User::factory()->onboarded()->create());
    $token = $summary->mintShareToken();

    $this->get("/s/{$token}")->assertOk();

    $summary->revokeShareToken();

    $this->get("/s/{$token}")->assertNotFound();
});

it('keeps the same token when the owner asks twice', function (): void {
    $summary = summaryFor(User::factory()->onboarded()->create());

    expect($summary->mintShareToken())->toBe($summary->fresh()->mintShareToken());
});

it('lets the owner mint and revoke the link from the app', function (): void {
    $user = User::factory()->onboarded()->create();
    $summary = summaryFor($user);

    $this->actingAs($user)->post("/summaries/{$summary->id}/share")->assertRedirect();
    expect($summary->fresh()->share_token)->not->toBeNull();

    $this->actingAs($user)->delete("/summaries/{$summary->id}/share")->assertRedirect();
    expect($summary->fresh()->share_token)->toBeNull();
});

it('will not let one reader touch another reader\'s summary', function (): void {
    $summary = summaryFor(User::factory()->onboarded()->create());
    $stranger = User::factory()->onboarded()->create();

    $this->actingAs($stranger)->post("/summaries/{$summary->id}/share")->assertNotFound();
    $this->actingAs($stranger)->get("/summaries/{$summary->id}/card/savings_rate/feed")->assertNotFound();

    expect($summary->fresh()->share_token)->toBeNull();
});

it('paints the card inside the screen and saves it on download', function (): void {
    // The screen shows the 4:5 render as a preview, so one route has to be able
    // to hand the browser a picture as well as a file to keep.
    Storage::fake('public');
    Storage::disk('public')->put('monthly-summaries/x/card.png', 'png-bytes');

    $user = User::factory()->onboarded()->create();
    $summary = summaryFor($user);

    $preview = $this->actingAs($user)
        ->get("/summaries/{$summary->id}/card/savings_rate/feed?preview=1")
        ->assertOk();

    expect($preview->headers->get('Content-Disposition'))->toStartWith('inline');
    expect($preview->headers->get('Cache-Control'))->toContain('max-age=300');

    $this->actingAs($user)
        ->get("/summaries/{$summary->id}/card/savings_rate/feed")
        ->assertOk()
        ->assertDownload("whisper-money-{$summary->period}-savings_rate-feed.png");
});

it('refuses a card format or kind it does not have', function (): void {
    $user = User::factory()->onboarded()->create();
    $summary = summaryFor($user);

    $this->actingAs($user)->get("/summaries/{$summary->id}/card/savings_rate/billboard")->assertNotFound();
    $this->actingAs($user)->get("/summaries/{$summary->id}/card/vibes/feed")->assertNotFound();
});

it('refuses a card the month has no figures for', function (): void {
    // Otherwise a hand-typed URL renders a goal card reading "0.0% of  already
    // saved" for a reader who has no goals.
    $user = User::factory()->onboarded()->create();
    $summary = MonthlySummary::factory()->sent()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'payload' => ['currency' => 'EUR', 'has_history' => true, 'goal' => null],
    ]);

    $this->actingAs($user)->get("/summaries/{$summary->id}/card/savings_goal/feed")->assertNotFound();
});

it('names every format the card can be downloaded in', function (): void {
    expect(array_map(fn (MonthlySummaryFormat $format): array => $format->dimensions(), MonthlySummaryFormat::cases()))
        ->toBe([[1080, 1350], [1080, 1920], [1200, 675]]);
});
