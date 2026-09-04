<?php

use App\Enums\CategoryType;
use App\Mail\Drip\MonthlySummaryEmail;
use App\Mail\Drip\MonthlySummaryReminderEmail;
use App\Models\Account;
use App\Models\Category;
use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\CardRenderer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Mail;

/*
 * The preview command. It is a local tool, but two of its details are easy to
 * get wrong and impossible to notice by eye: previews arriving in the wrong
 * language, and every case looking identical.
 */

beforeEach(function (): void {
    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('url')->andReturn('https://whisper.money/card.png');
        $mock->shouldReceive('warm');
    });
});

function previewSource(): User
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'es']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);

    foreach ([0, 1] as $ago) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category->id,
            'currency_code' => 'EUR',
            'amount' => -50000,
            'transaction_date' => now()->subMonths($ago + 1)->startOfMonth()->addDays(3),
        ]);
    }

    return $user;
}

it('sends every state of the report in one go', function (): void {
    Mail::fake();

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
    ])->assertSuccessful();

    Mail::assertSentCount(6);
    Mail::assertSent(MonthlySummaryReminderEmail::class);
});

it('draws every card the report screen shows, and keeps the earlier months', function (): void {
    // The email carries one card; the screen puts all of them up. A preview that
    // drew only the one in the email left the screen looking broken. Nor may a
    // look at a report cost a real reader the pictures of their older ones.
    Mail::fake();

    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('url')->andReturn('https://whisper.money/card.png');
        $mock->shouldReceive('warm')->once();
        $mock->shouldNotReceive('forgetBefore');
    });

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
        '--type' => 'pro',
    ])->assertSuccessful();
});

it('sends now rather than queueing, so the cases do not collapse into one', function (): void {
    // Queued, `SerializesModels` rebuilds the mailable in the worker and the
    // unsaved "incomplete" flag disappears — every preview then looks the same.
    Mail::fake();

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
    ])->assertSuccessful();

    Mail::assertNothingQueued();

    $incomplete = collect(Mail::sent(MonthlySummaryEmail::class))
        ->contains(fn (MonthlySummaryEmail $mail): bool => ! $mail->summary->complete);

    expect($incomplete)->toBeTrue();
});

it('previews in the reader\'s language, not the console\'s', function (): void {
    // The preview goes to a bare address, and a string has no locale preference,
    // so without stating it every preview arrives in English.
    Mail::fake();
    $source = previewSource();

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => $source->email,
    ])->assertSuccessful();

    Mail::assertSent(MonthlySummaryEmail::class, fn (MonthlySummaryEmail $mail): bool => $mail->locale === 'es');
});

it('refuses an address that is not one', function (): void {
    Mail::fake();

    $this->artisan('email:monthly-summary-preview', ['email' => 'not-an-address'])->assertFailed();

    Mail::assertNothingSent();
});

it('sends one case only when asked for one', function (string $type, string $mailable): void {
    Mail::fake();

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
        '--type' => $type,
    ])->assertSuccessful();

    Mail::assertSentCount(1);
    Mail::assertSent($mailable);
})->with([
    // Every slug, because a label in CASES with no matching case sends nothing
    // at all and still reports success.
    ['pro', MonthlySummaryEmail::class],
    ['free', MonthlySummaryEmail::class],
    ['pro-without-ai', MonthlySummaryEmail::class],
    ['short-closed-month', MonthlySummaryEmail::class],
    ['first-month', MonthlySummaryEmail::class],
    ['reminder', MonthlySummaryReminderEmail::class],
]);

it('refuses a case it does not have', function (): void {
    Mail::fake();

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
        '--type' => 'pro-with-sprinkles',
    ])->expectsOutputToContain('short-closed-month')->assertFailed();

    Mail::assertNothingSent();
});

it('writes the analysis with the model on --ai, once, and stores none of it', function (): void {
    // Storing it would be worse than useless: the real send reads `ai_analysis`
    // first, so the reader's own report would arrive as this preview's text.
    Mail::fake();
    $this->mock(AnalysisWriter::class, fn ($mock) => $mock->shouldReceive('draft')->once()->andReturn('El modelo ha escrito esto.'));

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
        '--ai' => true,
    ])->assertSuccessful();

    Mail::assertSent(MonthlySummaryEmail::class, fn (MonthlySummaryEmail $mail): bool => $mail->analysis === 'El modelo ha escrito esto.');
    expect(MonthlySummary::query()->whereNotNull('ai_analysis')->exists())->toBeFalse();
});

it('never asks the model for a case that carries no analysis', function (): void {
    Mail::fake();
    $this->mock(AnalysisWriter::class, fn ($mock) => $mock->shouldNotReceive('draft'));

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
        '--type' => 'free',
        '--ai' => true,
    ])->assertSuccessful();

    Mail::assertSentCount(1);
});

it('falls back to the sample text when the model cannot be reached', function (): void {
    // A real send holds the report and comes back for an unreachable model, so
    // `draft()` hands the failure back rather than returning null. A preview has
    // nowhere to come back to: it says so and shows the sample.
    Mail::fake();
    $this->mock(AnalysisWriter::class, fn ($mock) => $mock->shouldReceive('draft')->andThrow(
        new ConnectionException('cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received'),
    ));

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
        '--type' => 'pro',
        '--ai' => true,
    ])->assertSuccessful();

    Mail::assertSent(MonthlySummaryEmail::class, fn (MonthlySummaryEmail $mail): bool => str_contains((string) $mail->analysis, '[TEXTO DE MUESTRA]'));
});

it('falls back to the sample text when the model gives up', function (): void {
    // An empty analysis block reads exactly like the locked one, which would
    // send whoever is reviewing looking for a bug that is not there.
    Mail::fake();
    $this->mock(AnalysisWriter::class, fn ($mock) => $mock->shouldReceive('draft')->andReturnNull());

    $this->artisan('email:monthly-summary-preview', [
        'email' => 'look@whisper.test',
        '--source' => previewSource()->email,
        '--type' => 'pro',
        '--ai' => true,
    ])->assertSuccessful();

    Mail::assertSent(MonthlySummaryEmail::class, fn (MonthlySummaryEmail $mail): bool => str_contains((string) $mail->analysis, '[TEXTO DE MUESTRA]'));
});
