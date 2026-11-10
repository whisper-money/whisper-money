<?php

use App\Enums\CategoryType;
use App\Enums\DripEmailType;
use App\Jobs\Drip\SendInactiveBankedEmailJob;
use App\Mail\Drip\InactiveBankedEmail;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use App\Models\UserSetting;
use App\Services\Notifications\WhileAwayRecap;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

// The 20th, so the month-to-date comparison has enough days behind it, and
// frozen so the day-relative timestamps cannot straddle midnight.
beforeEach(function () {
    config(['subscriptions.enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00'));
});

/**
 * An onboarded user with a bank connected whose last visit was
 * $inactiveDaysAgo days ago.
 */
function dormantBankedUser(int $inactiveDaysAgo = 14): User
{
    $user = User::factory()->onboarded()->subscribed()->create([
        'last_active_at' => now()->subDays($inactiveDaysAgo),
        'currency_code' => 'EUR',
    ]);

    BankingConnection::factory()->for($user)->create();

    return $user;
}

/**
 * A bank-synced expense of $amount minor units on $date, written at $createdAt.
 */
function syncedExpense(User $user, int $amount, string $date, ?Category $category = null, ?CarbonInterface $createdAt = null): Transaction
{
    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR'])->id,
        'category_id' => $category?->id,
        'amount' => $amount,
        'currency_code' => 'EUR',
        'transaction_date' => $date,
    ]);

    $transaction->forceFill(['created_at' => $createdAt ?? now()])->saveQuietly();

    return $transaction;
}

function sendInactiveBankedEmail(User $user): void
{
    (new SendInactiveBankedEmailJob($user, $user->last_active_at->toDateString()))->handle();
}

describe('selection', function () {
    it('queues the email for a Pro user with a bank who went quiet two weeks ago', function () {
        Bus::fake();

        $user = dormantBankedUser();

        $this->artisan('email:inactive-banked')->assertSuccessful();

        Bus::assertDispatched(
            SendInactiveBankedEmailJob::class,
            fn (SendInactiveBankedEmailJob $job): bool => $job->user->is($user)
                && $job->dormantOn === $user->last_active_at->toDateString(),
        );
    });

    it('skips users with no bank connected', function () {
        Bus::fake();

        User::factory()->onboarded()->subscribed()->create(['last_active_at' => now()->subDays(14)]);

        $this->artisan('email:inactive-banked')->assertSuccessful();

        Bus::assertNotDispatched(SendInactiveBankedEmailJob::class);
    });

    it('matches the fourteenth day exactly', function (int $inactiveDaysAgo) {
        Bus::fake();

        dormantBankedUser($inactiveDaysAgo);

        $this->artisan('email:inactive-banked')->assertSuccessful();

        Bus::assertNotDispatched(SendInactiveBankedEmailJob::class);
    })->with([7, 13, 15]);

    it('skips users who never finished onboarding', function () {
        Bus::fake();

        $user = User::factory()->notOnboarded()->subscribed()->create(['last_active_at' => now()->subDays(14)]);
        BankingConnection::factory()->for($user)->create();

        $this->artisan('email:inactive-banked')->assertSuccessful();

        Bus::assertNotDispatched(SendInactiveBankedEmailJob::class);
    });

    it('skips users without Pro', function () {
        Bus::fake();

        $user = User::factory()->onboarded()->create(['last_active_at' => now()->subDays(14)]);
        BankingConnection::factory()->for($user)->create();

        $this->artisan('email:inactive-banked')->assertSuccessful();

        Bus::assertNotDispatched(SendInactiveBankedEmailJob::class);
    });

    it('skips users who already cancelled and are in their grace period', function () {
        Bus::fake();

        $user = dormantBankedUser();
        $user->subscription('default')->update(['ends_at' => now()->addDays(10)]);

        $this->artisan('email:inactive-banked')->assertSuccessful();

        Bus::assertNotDispatched(SendInactiveBankedEmailJob::class);
    });

    it('includes users on a trial', function () {
        Bus::fake();

        $user = User::factory()->onboarded()->subscribed('trialing')->create(['last_active_at' => now()->subDays(14)]);
        $user->subscription('default')->update(['trial_ends_at' => now()->addDays(5)]);
        BankingConnection::factory()->for($user)->create();

        $this->artisan('email:inactive-banked')->assertSuccessful();

        Bus::assertDispatched(SendInactiveBankedEmailJob::class);
    });

    it('does nothing when drip emails are disabled', function () {
        config(['mail.drip_emails_enabled' => false]);
        Bus::fake();

        dormantBankedUser();

        $this->artisan('email:inactive-banked')->assertSuccessful();

        Bus::assertNotDispatched(SendInactiveBankedEmailJob::class);
    });

    it('does not queue again on a same-day re-run', function () {
        Mail::fake();

        $user = dormantBankedUser();

        $this->artisan('email:inactive-banked')->assertSuccessful();
        $this->artisan('email:inactive-banked')->assertSuccessful();

        Mail::assertQueuedCount(1);
        expect(UserMailLog::where('user_id', $user->id)->sole())
            ->email_type->toBe(DripEmailType::InactiveBanked)
            ->email_identifier->toBe($user->last_active_at->toDateString());
    });
});

describe('sending', function () {
    it('does not send once the user has come back', function () {
        Mail::fake();

        $user = dormantBankedUser();
        $dormantOn = $user->last_active_at->toDateString();

        $user->forceFill(['last_active_at' => now()])->save();

        (new SendInactiveBankedEmailJob($user, $dormantOn))->handle();

        Mail::assertNothingQueued();
    });

    it('does not send once Pro has lapsed', function () {
        Mail::fake();

        $user = dormantBankedUser();
        $user->subscriptions()->delete();

        sendInactiveBankedEmail($user->fresh());

        Mail::assertNothingQueued();
    });

    it('does not send to a user who switched off news and offers', function () {
        Mail::fake();

        $user = dormantBankedUser();
        UserSetting::factory()->for($user)->create(['notify_marketing' => false]);

        sendInactiveBankedEmail($user->fresh());

        Mail::assertNothingQueued();
        expect(UserMailLog::where('user_id', $user->id)->exists())->toBeFalse();
    });

    it('stands down when another nudge went out in the last few days', function () {
        Mail::fake();

        $user = dormantBankedUser();
        UserMailLog::create([
            'user_id' => $user->id,
            'email_type' => DripEmailType::MonthlySummaryReminder,
            'email_identifier' => '2026-10',
            'sent_at' => now()->subDays(2),
        ]);

        sendInactiveBankedEmail($user);

        Mail::assertNothingQueued();
    });

    it('is a marketing nudge', function () {
        expect(DripEmailType::InactiveBanked->isMarketing())->toBeTrue()
            ->and(DripEmailType::nudges())->toContain(DripEmailType::InactiveBanked);
    });
});

describe('recap', function () {
    it('counts the bank rows synced since the last visit and the uncategorized ones', function () {
        $user = dormantBankedUser();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);

        syncedExpense($user, -1000, '2026-11-15', $category, now()->subDays(5));
        syncedExpense($user, -1000, '2026-11-16', null, now()->subDays(4));
        syncedExpense($user, -1000, '2026-11-01', null, now()->subDays(20));
        Transaction::factory()->create(['user_id' => $user->id, 'category_id' => null]);

        $recap = app(WhileAwayRecap::class)->for($user, CarbonImmutable::parse($user->last_active_at));

        expect($recap['newTransactions'])->toBe(2)
            ->and($recap['uncategorized'])->toBe(1);
    });

    it('compares month-to-date spending with the same days of last month', function () {
        $user = dormantBankedUser();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);

        syncedExpense($user, -10000, '2026-10-10', $category);
        syncedExpense($user, -50000, '2026-10-25', $category);
        syncedExpense($user, -8000, '2026-11-10', $category);

        $recap = app(WhileAwayRecap::class)->for($user, CarbonImmutable::parse($user->last_active_at));

        expect($recap['spendingChange'])->toBe(-20.0);
    });

    it('skips the comparison when nothing has been spent yet this month', function () {
        $user = dormantBankedUser();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);

        syncedExpense($user, -10000, '2026-10-10', $category);

        $recap = app(WhileAwayRecap::class)->for($user, CarbonImmutable::parse($user->last_active_at));

        expect($recap['spendingChange'])->toBeNull();
    });

    it('skips the comparison early in the month', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));
        $user = dormantBankedUser();

        $recap = app(WhileAwayRecap::class)->for($user, CarbonImmutable::parse($user->last_active_at));

        expect($recap['spendingChange'])->toBeNull();
    });
});

describe('content', function () {
    it('sends the recap built from the user data', function () {
        Mail::fake();

        $user = dormantBankedUser();
        syncedExpense($user, -1000, '2026-11-15');

        sendInactiveBankedEmail($user);

        Mail::assertQueued(
            InactiveBankedEmail::class,
            fn (InactiveBankedEmail $mail): bool => $mail->recap['newTransactions'] === 1
                && $mail->recap['uncategorized'] === 1,
        );
    });

    it('leads with what synced, in counts and percentages only', function () {
        $mail = new InactiveBankedEmail(User::factory()->create(['name' => 'Ana']), [
            'newTransactions' => 37,
            'uncategorized' => 5,
            'spendingChange' => -12.4,
        ]);

        $html = $mail->render();

        expect($mail->envelope()->subject)->toBe('37 new transactions came in while you were away')
            ->and($html)->toContain('While you were away, Ana')
            ->toContain('37 new transactions')
            ->toContain('of them still need a category')
            ->toContain('12% less than by this point last month')
            ->toContain(route('transactions.index').'?utm_source=drip&amp;utm_medium=email&amp;utm_campaign=inactive-banked')
            ->toContain('Stop receiving news and offers')
            ->not->toContain('€');
    });

    it('points to the connections when nothing synced', function () {
        $mail = new InactiveBankedEmail(User::factory()->create(['name' => 'Ana']), [
            'newTransactions' => 0,
            'uncategorized' => 0,
            'spendingChange' => null,
        ]);

        $html = $mail->render();

        expect($mail->envelope()->subject)->toBe('Nothing has synced from your bank in two weeks')
            ->and($html)->toContain('Your bank has gone quiet, Ana')
            ->toContain(route('settings.connections.index').'?')
            ->not->toContain(route('transactions.index').'?');
    });

    it('is written in the reader language', function () {
        app()->setLocale('es');

        $mail = new InactiveBankedEmail(User::factory()->create(['name' => 'Ana']), [
            'newTransactions' => 1,
            'uncategorized' => 1,
            'spendingChange' => 30.0,
        ]);

        expect($mail->envelope()->subject)->toBe('Mientras no estabas llegó 1 transacción nueva')
            ->and($mail->render())->toContain('1 transacción nueva')
            ->toContain('un 30');
    });
});
