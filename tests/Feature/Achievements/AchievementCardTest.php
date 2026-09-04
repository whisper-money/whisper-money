<?php

use App\Models\Achievement;
use App\Models\User;
use App\Services\Achievements\CardRenderer;
use App\Services\Achievements\Catalog;
use App\Services\Achievements\Definition;
use App\Services\Achievements\Pictograms;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * The shareable medal.
 *
 * Chromium is faked — a file written where each job asked for its PNG — and the
 * HTML it was handed is kept as it goes past, which is where the copy inside a
 * card can be read without decoding a picture. Same approach as
 * `MonthlySummary/CardRenderTest`, same reason: what is under test is what the
 * card says and who is allowed to ask for it, not that Chromium can take a
 * screenshot.
 */

beforeEach(function (): void {
    Cache::flush();
    Storage::fake(CardRenderer::DISK);
    config()->set('achievements.enabled', true);

    $this->drawnHtml = [];

    Process::fake(function (PendingProcess $process) {
        $manifest = json_decode((string) file_get_contents((string) last($process->command)), true);

        foreach ($manifest as $job) {
            $this->drawnHtml[$job['path']] = (string) file_get_contents($job['html']);
            file_put_contents($job['png'], 'png');
        }

        return Process::result('');
    });
});

function medalOwner(): User
{
    return User::factory()->onboarded()->create(['currency_code' => 'EUR']);
}

function earned(User $user, string $key, array $attributes = []): Achievement
{
    return Achievement::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'key' => $key,
        ...$attributes,
    ]);
}

/** The HTML the one card in this test was drawn from. */
function drawnCard(): string
{
    expect(test()->drawnHtml)->toHaveCount(1);

    return (string) collect(test()->drawnHtml)->first();
}

/**
 * Only the words a reader would see on the card.
 *
 * Asking whether a character is in the copy has to go through this: the
 * stylesheet is full of `50%` and the medal's own SVG positions its engraving
 * with `translate: -50%`, so the raw HTML answers a different question.
 */
function drawnCardText(): string
{
    $body = (string) last(explode('</style>', drawnCard()));

    return trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
}

it('serves an earned medal in both shapes and both skins', function (string $format, string $theme): void {
    $user = medalOwner();
    earned($user, 'streaks.2');

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'streaks.2', 'format' => $format, 'theme' => $theme]))
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
})->with([
    ['feed', 'light'],
    ['feed', 'dark'],
    ['story', 'light'],
    ['story', 'dark'],
]);

it('draws the 4:5 and the 9:16 at the sizes the networks want', function (string $format, int $width, int $height): void {
    $user = medalOwner();
    earned($user, 'streaks.2');

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'streaks.2', 'format' => $format, 'theme' => 'light']))
        ->assertOk();

    expect(drawnCard())->toContain("width: {$width}px", "height: {$height}px");
})->with([
    ['feed', 1080, 1350],
    ['story', 1080, 1920],
]);

it('refuses a shape the medal card is not designed for', function (): void {
    $user = medalOwner();
    earned($user, 'streaks.2');

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'streaks.2', 'format' => 'wide', 'theme' => 'light']))
        ->assertNotFound();
});

it('will not hand out a medal the reader has not earned', function (): void {
    $user = medalOwner();
    earned($user, 'streaks.2');

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'net_worth.7', 'format' => 'feed', 'theme' => 'light']))
        ->assertNotFound();
});

it('will not hand out somebody else\'s medal', function (): void {
    $owner = medalOwner();
    earned($owner, 'streaks.2');

    test()->actingAs(medalOwner())
        ->get(route('achievements.card', ['medal' => 'streaks.2', 'format' => 'feed', 'theme' => 'light']))
        ->assertNotFound();
});

it('writes the amount on a money medal by default', function (): void {
    $user = medalOwner();
    earned($user, 'monthly_saving.1');

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'monthly_saving.1', 'format' => 'feed', 'theme' => 'light']))
        ->assertOk();

    // A milestone is a round number, so it is written without decimals.
    expect(drawnCard())
        ->toContain('€')
        ->not->toContain(',00');
});

it('leaves the amount off when the reader asks it to', function (): void {
    $user = medalOwner();
    earned($user, 'monthly_saving.1');

    test()->actingAs($user)
        ->get(route('achievements.card', [
            'medal' => 'monthly_saving.1', 'format' => 'feed', 'theme' => 'light', 'amount' => 0,
        ]))
        ->assertOk();

    expect(drawnCard())
        ->not->toContain('€')
        // The medal is still the medal: the name and the tier carry it.
        ->toContain('Saved in a month');
});

it('keeps a figure that was never an amount, whatever the amount flag says', function (): void {
    $user = medalOwner();
    earned($user, 'savings_rate.1', ['value' => null, 'percent' => 24.0, 'currency_code' => null]);

    test()->actingAs($user)
        ->get(route('achievements.card', [
            'medal' => 'savings_rate.1', 'format' => 'feed', 'theme' => 'light', 'amount' => 0,
        ]))
        ->assertOk();

    // A rate is safe to post, so hiding amounts does not blank it.
    expect(drawnCard())->toContain('20');
});

it('never writes the share of members on the card', function (): void {
    $user = medalOwner();
    earned($user, 'streaks.2');
    config()->set('achievements.rarity_floor', 1);

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'streaks.2', 'format' => 'feed', 'theme' => 'light']))
        ->assertOk();

    // That number is for inside the app: it says how many other people hold the
    // medal, which is nobody's business on a picture posted to a feed. This
    // medal's own figure is a run of months, so no percentage belongs in the
    // copy at all — the rate medals that do carry one are covered above.
    expect(drawnCardText())
        ->toContain('Uncommon')
        ->not->toContain('%');
});

it('draws each cut once and serves the rest off the disk', function (): void {
    $user = medalOwner();
    earned($user, 'streaks.2');

    $ask = fn (array $query = []) => test()->actingAs($user)->get(route('achievements.card', [
        'medal' => 'streaks.2', 'format' => 'feed', 'theme' => 'light', ...$query,
    ]))->assertOk();

    $ask();
    $ask();

    Process::assertRanTimes(fn (PendingProcess $process): bool => in_array(
        base_path('scripts/render-card.mjs'), $process->command, true,
    ), 1);

    // Hiding the amount is a different picture, so it is a different file.
    $ask(['amount' => 0]);

    Process::assertRanTimes(fn (PendingProcess $process): bool => in_array(
        base_path('scripts/render-card.mjs'), $process->command, true,
    ), 2);
});

it('strikes the medal in the metal of its tier', function (string $key, string $core): void {
    $user = medalOwner();
    earned($user, $key);

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => $key, 'format' => 'feed', 'theme' => 'light']))
        ->assertOk();

    expect(drawnCard())->toContain($core);
})->with([
    // Copper, steel, gold — and obsidian, which is the only one that also has
    // to carry a second metal for its crown.
    ['transactions.1', 'oklch(0.60 0.13 38)'],
    ['streaks.2', 'oklch(0.73 0.014 254)'],
    ['streaks.3', 'oklch(0.79 0.155 86)'],
    ['streaks.4', 'oklch(0.255 0.015 266)'],
]);

it('gives obsidian a gold crown', function (): void {
    $user = medalOwner();
    earned($user, 'streaks.4');

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'streaks.4', 'format' => 'feed', 'theme' => 'light']))
        ->assertOk();

    // Both metals in one drawing: the dark face and the gold it is crowned with.
    expect(drawnCard())
        ->toContain('oklch(0.255 0.015 266)')
        ->toContain('oklch(0.79 0.155 86)');
});

it('can draw every pictogram the catalog asks for', function (): void {
    // A card cannot import the frontend's icon set, so it keeps its own copy of
    // the geometry. An icon added to the catalog and not to that copy falls
    // back to a bullseye and nobody notices until it is posted — which has
    // already happened once.
    $asked = app(Catalog::class)->all()
        ->map(fn (Definition $definition): string => $definition->icon)
        ->unique()
        ->values()
        ->all();

    expect(array_diff($asked, app(Pictograms::class)->names()))->toBeEmpty();
});

it('keeps the picture off any disk the web serves without asking who is asking', function (): void {
    $user = medalOwner();
    earned($user, 'monthly_saving.1');

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'monthly_saving.1', 'format' => 'feed', 'theme' => 'light']))
        ->assertOk();

    // `storage:link` publishes the public disk at /storage, so a card written
    // there is readable by anyone holding the URL — and this card carries the
    // reader's amount by default. The private disk has no URL to hold.
    expect(CardRenderer::DISK)->not->toBe('public');
    expect(Storage::disk(CardRenderer::DISK)->allFiles())->not->toBeEmpty();
});

it('is closed while the feature is off', function (): void {
    $user = medalOwner();
    earned($user, 'streaks.2');
    config()->set('achievements.enabled', false);

    test()->actingAs($user)
        ->get(route('achievements.card', ['medal' => 'streaks.2', 'format' => 'feed', 'theme' => 'light']))
        ->assertNotFound();
});
