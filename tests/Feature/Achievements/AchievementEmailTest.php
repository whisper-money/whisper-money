<?php

use App\Mail\Drip\AchievementsEmail;
use App\Models\Achievement;
use App\Models\User;
use App\Services\Achievements\CardRenderer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
 * The message a sweep sends.
 *
 * Chromium is faked the way `AchievementCardTest` fakes it — a file written
 * where each job asked for its PNG — because what is under test is which
 * pictures the email carries and how it carries them, not that a screenshot can
 * be taken.
 */

beforeEach(function (): void {
    Storage::fake(CardRenderer::DISK);
    config()->set('achievements.enabled', true);

    Process::fake(function (PendingProcess $process) {
        $manifest = json_decode((string) file_get_contents((string) last($process->command)), true);

        foreach ($manifest as $job) {
            file_put_contents($job['png'], 'png');
        }

        return Process::result('');
    });
});

/**
 * @param  list<string>  $keys
 */
function announcement(array $keys, ?User $user = null): AchievementsEmail
{
    $user ??= User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    $medals = collect($keys)->map(fn (string $key): Achievement => Achievement::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'key' => $key,
    ]));

    return new AchievementsEmail($user, $medals);
}

it('carries a picture of every medal it announces', function (): void {
    $mail = announcement(['streaks.2', 'transactions.1']);

    $mail->assertSeeInHtml('cid:streaks.2.png', escape: false);
    $mail->assertSeeInHtml('cid:transactions.1.png', escape: false);

    // Light and 4:5, the shape the share dialog and the public page already use.
    expect(Storage::disk(CardRenderer::DISK)->allFiles())
        ->toHaveCount(2)
        ->each->toContain('-feed-light-en');
});

it('warms the very file the share dialog will serve a Pro reader', function (): void {
    config()->set('subscriptions.enabled', true);

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $assertDrawn = fn (int $times) => Process::assertRanTimes(fn (PendingProcess $process): bool => in_array(
        base_path('scripts/render-card.mjs'), $process->command, true,
    ), $times);

    announcement(['streaks.2'], $user)->render();

    $assertDrawn(1);

    test()->actingAs($user->unsetRelation('subscriptions'))
        ->get(route('achievements.card', ['medal' => 'streaks.2', 'format' => 'feed', 'theme' => 'light']))
        ->assertOk();

    // Still one: the email and the dialog read the badge off the same flag, so
    // they name the same file and the dialog gets the picture the email already
    // drew. Disagree on it and the medal is drawn twice, once wrong.
    $assertDrawn(1);
});

/**
 * The medal grid on its own, so a count of rows or cells is a count of medals
 * rather than of the layout the Markdown mail wraps them in.
 */
function medalGrid(string $html): string
{
    preg_match('/<table class="medals".*?<\/table>/s', $html, $matches);

    return $matches[0] ?? '';
}

it('lays the medals out two to a row, every card the same width', function (): void {
    $grid = medalGrid(announcement(['visits.1', 'visits.2', 'visits.3'])->render());

    // Every card in a cell of the same width: a column that has to hold a
    // gutter as well as a card is a narrower column, and the card inside it is
    // the one the client scales down. The third medal starts a second row at
    // that same width rather than stretching across.
    expect(substr_count($grid, 'width: 50%'))->toBe(3)
        ->and(substr_count($grid, '<tr>'))->toBe(2);
});

it('centres a lone medal rather than leaving half a row empty', function (): void {
    $grid = medalGrid(announcement(['visits.1'])->render());

    expect($grid)->toContain('align="center"')
        ->and($grid)->not->toContain('width: 50%');
});

it('links to the progress screen', function (): void {
    announcement(['streaks.2'])->assertSeeInHtml('utm_content=progress', escape: false);
});

it('still names every medal for a reader whose client blocks images', function (): void {
    $mail = announcement(['streaks.2']);

    $mail->assertSeeInText('Saving streak');
    $mail->assertDontSeeInText('cid:');
});

it('stops short of mailing a whole history as pictures', function (): void {
    $mail = announcement(['visits.1', 'visits.2', 'visits.3', 'visits.4', 'visits.5']);

    $mail->assertSeeInHtml('cid:visits.3.png', escape: false);
    $mail->assertDontSeeInHtml('cid:visits.4.png', escape: false);

    expect(Storage::disk(CardRenderer::DISK)->allFiles())->toHaveCount(3)
        // The list is the message, so it keeps the medals the pictures dropped.
        ->and(substr_count($mail->render(), '<li'))->toBe(5);
});

it('shows the card inside the message rather than attaching a file', function (): void {
    $mail = announcement(['streaks.2']);

    Mail::to('reader@example.test')->send($mail);

    $sent = (string) Mail::mailer()->getSymfonyTransport()->messages()->first()->getOriginalMessage()->toString();

    // Symfony turns the attachment into a related inline part because the HTML
    // asks for it by name, which is the whole reason it is named after the medal.
    expect($sent)->toContain('Content-Disposition: inline')
        ->and($sent)->not->toContain('Content-Disposition: attachment');
});

it('goes out without the pictures when the browser cannot draw them', function (): void {
    Exceptions::fake();
    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'no browser'));

    $mail = announcement(['streaks.2']);

    $mail->assertDontSeeInHtml('cid:', escape: false);
    $mail->assertSeeInText('Saving streak');

    // And no grid where the pictures would have gone: an empty table is a
    // pocket of air the reader has to scroll past for nothing.
    expect(medalGrid($mail->render()))->toBe('');

    Exceptions::assertReported(RuntimeException::class);
});
