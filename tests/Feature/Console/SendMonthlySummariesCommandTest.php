<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\CategoryType;
use App\Enums\DripEmailType;
use App\Features\MonthlySummaries;
use App\Jobs\Drip\SendMonthlySummaryEmailJob;
use App\Jobs\Drip\SendMonthlySummaryReminderEmailJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;

/*
 * The send window.
 *
 * Three things are being pinned down here, and each of them was a decision:
 * the report waits until the 3rd and then retries daily until the 10th; the
 * hour is local to the reader, not Madrid; and a month that is still moving is
 * not reported until it stops, except on the last day, when it goes out anyway.
 */

beforeEach(function (): void {
    Feature::define(MonthlySummaries::class, fn (): bool => true);
});

/**
 * Move the clock to a given day of the current month at 09:00 in Madrid, which
 * is the hour the command sends at.
 */
function travelToWindowDay(int $day, string $timezone = 'Europe/Madrid'): void
{
    test()->travelTo(
        Carbon\Carbon::create(2026, 10, $day, 9, 0, 0, $timezone)->utc()
    );
}

/**
 * An onboarded reader with a closed month worth reporting.
 */
function readerWithClosedMonth(string $timezone = 'Europe/Madrid'): User
{
    $user = User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
        'timezone' => $timezone,
        'last_active_at' => now()->subDays(2),
    ]);

    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'currency_code' => 'EUR',
        'amount' => -50000,
        'transaction_date' => now()->subMonth()->startOfMonth()->addDays(4),
        'created_at' => now()->subMonth()->startOfMonth()->addDays(4),
    ]);

    return $user;
}

/**
 * Something happened in the new month, so the closed one is settled.
 */
function withActivityInTheNewMonth(User $user): void
{
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $user->accounts()->first()->id,
        'currency_code' => 'EUR',
        'amount' => -1000,
        'transaction_date' => now()->startOfMonth()->addDay(),
        'created_at' => now()->startOfMonth()->addDay(),
    ]);
}

/**
 * Neither the report nor the reminder went out. Not `assertNothingDispatched`:
 * creating a user queues the registration drip jobs, which are none of our
 * business here.
 */
function assertNoSummaryMail(): void
{
    Bus::assertNotDispatched(SendMonthlySummaryEmailJob::class);
    Bus::assertNotDispatched(SendMonthlySummaryReminderEmailJob::class);
}

it('sends nothing before the window opens', function (): void {
    Bus::fake();
    travelToWindowDay(2);
    withActivityInTheNewMonth(readerWithClosedMonth());

    $this->artisan('email:monthly-summary')->assertSuccessful();

    assertNoSummaryMail();
});

it('sends the report on the first day of the window when the month is settled', function (): void {
    Bus::fake();
    travelToWindowDay(3);
    $user = readerWithClosedMonth();
    withActivityInTheNewMonth($user);

    $this->artisan('email:monthly-summary')->assertSuccessful();

    Bus::assertDispatched(
        SendMonthlySummaryEmailJob::class,
        fn (SendMonthlySummaryEmailJob $job): bool => $job->user->is($user)
            && $job->summary->period === now()->subMonth()->format('Y-m')
            && $job->summary->complete,
    );
});

it('reminds instead of reporting while the month is still moving', function (): void {
    Bus::fake();
    travelToWindowDay(3);
    $user = readerWithClosedMonth();

    $this->artisan('email:monthly-summary')->assertSuccessful();

    Bus::assertDispatched(
        SendMonthlySummaryReminderEmailJob::class,
        fn (SendMonthlySummaryReminderEmailJob $job): bool => $job->user->is($user)
            && $job->period === now()->subMonth()->format('Y-m'),
    );
    Bus::assertNotDispatched(SendMonthlySummaryEmailJob::class);
});

it('stays quiet on the days between, without reminding twice', function (): void {
    Bus::fake();
    travelToWindowDay(5);
    readerWithClosedMonth();

    $this->artisan('email:monthly-summary')->assertSuccessful();

    assertNoSummaryMail();
});

it('sends the report on the last day even when the month never settled', function (): void {
    Bus::fake();
    travelToWindowDay(10);
    $user = readerWithClosedMonth();

    $this->artisan('email:monthly-summary')->assertSuccessful();

    Bus::assertDispatched(
        SendMonthlySummaryEmailJob::class,
        fn (SendMonthlySummaryEmailJob $job): bool => $job->user->is($user) && ! $job->summary->complete,
    );
});

it('accepts a successful bank sync as the sign the month is settled', function (): void {
    Bus::fake();
    travelToWindowDay(3);
    $user = readerWithClosedMonth();

    BankingConnection::factory()->for($user)->create([
        'status' => BankingConnectionStatus::Active,
        'last_synced_at' => now()->startOfMonth()->addDay(),
    ]);

    $this->artisan('email:monthly-summary')->assertSuccessful();

    Bus::assertDispatched(SendMonthlySummaryEmailJob::class);
});

it('sends at nine in the reader\'s own timezone, not ours', function (): void {
    Bus::fake();

    // 09:00 in Bogotá is 16:00 in Madrid, so a Madrid-hour command would miss
    // this reader entirely — and that is most of the user base.
    travelToWindowDay(3, 'Europe/Madrid');
    $user = readerWithClosedMonth('America/Bogota');
    withActivityInTheNewMonth($user);

    $this->artisan('email:monthly-summary')->assertSuccessful();
    Bus::assertNotDispatched(SendMonthlySummaryEmailJob::class);

    travelToWindowDay(3, 'America/Bogota');
    $this->artisan('email:monthly-summary')->assertSuccessful();
    Bus::assertDispatched(SendMonthlySummaryEmailJob::class);
});

it('never reports the same month twice', function (): void {
    Bus::fake();
    travelToWindowDay(3);
    $user = readerWithClosedMonth();
    withActivityInTheNewMonth($user);

    UserMailLog::create([
        'user_id' => $user->id,
        'email_type' => DripEmailType::MonthlySummary,
        'email_identifier' => now()->subMonth()->format('Y-m').':'.$user->activeSpace()->id,
        'sent_at' => now(),
    ]);

    $this->artisan('email:monthly-summary')->assertSuccessful();

    assertNoSummaryMail();
});

it('skips readers who turned the summary off', function (): void {
    Bus::fake();
    travelToWindowDay(3);
    $user = readerWithClosedMonth();
    withActivityInTheNewMonth($user);
    $user->setting()->updateOrCreate(['user_id' => $user->id], ['notify_monthly_summary' => false]);

    $this->artisan('email:monthly-summary')->assertSuccessful();

    assertNoSummaryMail();
});

it('skips readers the feature is not on for', function (): void {
    Bus::fake();
    Feature::define(MonthlySummaries::class, fn (): bool => false);
    travelToWindowDay(3);
    withActivityInTheNewMonth(readerWithClosedMonth());

    $this->artisan('email:monthly-summary')->assertSuccessful();

    assertNoSummaryMail();
});

it('does not remind a reader who has no month to report at all', function (): void {
    Bus::fake();
    travelToWindowDay(3);

    // Onboarded, active, but never a transaction: there is nothing to nudge
    // them towards and nothing to report.
    User::factory()->onboarded()->create(['timezone' => 'Europe/Madrid', 'last_active_at' => now()]);

    $this->artisan('email:monthly-summary')->assertSuccessful();

    assertNoSummaryMail();
});

it('does not remind a dormant reader', function (): void {
    Bus::fake();
    travelToWindowDay(3);
    $user = readerWithClosedMonth();
    $user->forceFill(['last_active_at' => now()->subMonths(4)])->save();

    $this->artisan('email:monthly-summary')->assertSuccessful();

    assertNoSummaryMail();
});
