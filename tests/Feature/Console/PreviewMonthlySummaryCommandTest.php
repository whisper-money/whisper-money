<?php

use App\Enums\CategoryType;
use App\Mail\Drip\MonthlySummaryEmail;
use App\Mail\Drip\MonthlySummaryReminderEmail;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\CardRenderer;
use Illuminate\Support\Facades\Mail;

/*
 * The preview command. It is a local tool, but two of its details are easy to
 * get wrong and impossible to notice by eye: previews arriving in the wrong
 * language, and every case looking identical.
 */

beforeEach(function (): void {
    $this->mock(CardRenderer::class, fn ($mock) => $mock->shouldReceive('url')->andReturn('https://whisper.money/card.png'));
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
