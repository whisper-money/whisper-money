<?php

use App\Enums\BudgetNotificationType;
use App\Mail\BudgetNotificationEmail;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->service = app(BudgetTransactionService::class);
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
});

function budgetPeriodFor(User $user, Category $category, int $allocated, array $toggles): BudgetPeriod
{
    $budget = Budget::factory()->forCategories($category)->create(array_merge(
        ['user_id' => $user->id],
        $toggles,
    ));

    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDays(10),
        'end_date' => now()->addDays(20),
        'allocated_amount' => $allocated,
    ]);
}

function assignExpense(BudgetTransactionService $service, User $user, Category $category, int $amountCents): Transaction
{
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'transaction_date' => now()->subDay(),
        'amount' => -$amountCents,
    ]);

    $service->assignTransaction($transaction);

    return $transaction;
}

test('sends a new transaction email when the budget opts in', function () {
    Mail::fake();
    budgetPeriodFor($this->user, $this->category, 100000, ['notify_on_new_transaction' => true]);

    assignExpense($this->service, $this->user, $this->category, 1000);

    Mail::assertQueued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type === BudgetNotificationType::NewTransaction
            && $mail->hasTo($this->user->email),
    );
});

test('does not send a new transaction email when the budget opts out', function () {
    Mail::fake();
    budgetPeriodFor($this->user, $this->category, 100000, ['notify_on_new_transaction' => false]);

    assignExpense($this->service, $this->user, $this->category, 1000);

    Mail::assertNotQueued(BudgetNotificationEmail::class);
});

test('sends an over limit email once the limit is exceeded', function () {
    Mail::fake();
    budgetPeriodFor($this->user, $this->category, 1000, ['notify_on_over_limit' => true]);

    assignExpense($this->service, $this->user, $this->category, 1500);

    Mail::assertQueued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type === BudgetNotificationType::OverLimit,
    );
});

test('sends a close to limit email at 90 percent of the limit', function () {
    Mail::fake();
    budgetPeriodFor($this->user, $this->category, 1000, ['notify_on_close_to_limit' => true]);

    assignExpense($this->service, $this->user, $this->category, 900);

    Mail::assertQueued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type === BudgetNotificationType::CloseToLimit,
    );
});

test('renders every budget notification email variant', function () {
    $period = budgetPeriodFor($this->user, $this->category, 5000, []);
    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'transaction_date' => now()->subDay(),
        'amount' => -6000,
    ]);
    $this->service->assignTransaction($transaction);

    // Every variant can carry the triggering transaction now that a limit alert
    // coalesces the "new transaction" notice into itself.
    foreach (BudgetNotificationType::cases() as $type) {
        $html = (new BudgetNotificationEmail(
            $this->user,
            $period->budget,
            $period->fresh(),
            $type,
            $transaction,
        ))->render();

        expect($html)
            ->toContain($period->budget->name)
            ->toContain(route('notifications.index'));
    }
});

test('a single limit-crossing transaction sends only the over-limit email', function () {
    Mail::fake();
    budgetPeriodFor($this->user, $this->category, 1000, [
        'notify_on_new_transaction' => true,
        'notify_on_close_to_limit' => true,
        'notify_on_over_limit' => true,
    ]);

    // One transaction takes the budget straight past its limit: the user should
    // get a single email — the over-limit one — that still names the transaction.
    $transaction = assignExpense($this->service, $this->user, $this->category, 1500);

    expect(Mail::queued(BudgetNotificationEmail::class))->toHaveCount(1);
    Mail::assertQueued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type === BudgetNotificationType::OverLimit
            && $mail->transaction?->is($transaction)
            && $mail->hasTo($this->user->email),
    );
});

test('does not resend the over limit email on later transactions', function () {
    Mail::fake();
    budgetPeriodFor($this->user, $this->category, 1000, ['notify_on_over_limit' => true]);

    assignExpense($this->service, $this->user, $this->category, 1500);
    assignExpense($this->service, $this->user, $this->category, 200);

    expect(Mail::queued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type === BudgetNotificationType::OverLimit,
    ))->toHaveCount(1);
});

test('re-notifies over limit after spending drops below the threshold and crosses again', function () {
    Mail::fake();
    budgetPeriodFor($this->user, $this->category, 1000, ['notify_on_over_limit' => true]);

    assignExpense($this->service, $this->user, $this->category, 1500);

    // A refund pulls spending well back under the limit, resetting the flag.
    $refund = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'transaction_date' => now()->subDay(),
        'amount' => 1400,
    ]);
    $this->service->assignTransaction($refund);

    assignExpense($this->service, $this->user, $this->category, 1500);

    expect(Mail::queued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type === BudgetNotificationType::OverLimit,
    ))->toHaveCount(2);
});

test('does not send close or over emails when the budget has no limit', function () {
    Mail::fake();
    budgetPeriodFor($this->user, $this->category, 0, [
        'notify_on_new_transaction' => true,
        'notify_on_close_to_limit' => true,
        'notify_on_over_limit' => true,
    ]);

    assignExpense($this->service, $this->user, $this->category, 5000);

    Mail::assertQueued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type === BudgetNotificationType::NewTransaction,
    );
    expect(Mail::queued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type !== BudgetNotificationType::NewTransaction,
    ))->toHaveCount(0);
});

test('historical backfill assignment sends no emails', function () {
    $period = budgetPeriodFor($this->user, $this->category, 1000, [
        'notify_on_new_transaction' => true,
        'notify_on_over_limit' => true,
    ]);

    // Model these as transactions that already existed before the budget: their
    // creation listener is suppressed, so only the backfill method acts on them.
    Queue::fake();
    Transaction::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'transaction_date' => now()->subDays(2),
        'amount' => -800,
    ]);

    Mail::fake();
    $this->service->assignHistoricalTransactionsToPeriod($period);

    Mail::assertNotQueued(BudgetNotificationEmail::class);
});

test('notifies over limit when the preference is enabled while already over', function () {
    Mail::fake();
    $period = budgetPeriodFor($this->user, $this->category, 1000, ['notify_on_over_limit' => false]);

    assignExpense($this->service, $this->user, $this->category, 1500);
    Mail::assertNotQueued(BudgetNotificationEmail::class);

    $period->budget->update(['notify_on_over_limit' => true]);
    assignExpense($this->service, $this->user, $this->category, 100);

    Mail::assertQueued(
        BudgetNotificationEmail::class,
        fn (BudgetNotificationEmail $mail) => $mail->type === BudgetNotificationType::OverLimit,
    );
});
