<?php

use App\Enums\AccountType;
use App\Mail\MonthlySavingsGoalReminderEmail;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSetting;
use App\Notifications\MonthlySavingsGoalClosed;
use App\Services\Notifications\NotificationFeed;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

function noticesUser(): User
{
    return User::factory()->create(['onboarded_at' => now(), 'currency_code' => 'EUR']);
}

function noticesGoal(User $user, array $attributes = []): SavingsGoal
{
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id, ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

function noticesSave(SavingsGoal $goal, int $amount, string $date): void
{
    Transaction::factory()->create([
        'user_id' => $goal->user_id,
        'account_id' => Account::factory()->create(['user_id' => $goal->user_id, 'type' => AccountType::Savings])->id,
        'amount' => $amount,
        'transaction_date' => $date,
    ])->labels()->attach($goal->label_id);
}

beforeEach(fn () => Mail::fake());

test('closing a month rings the bell once with how it went', function () {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $user = noticesUser();
    $goal = noticesGoal($user, ['name' => 'Emergency fund']);
    noticesSave($goal, 31000, '2026-10-20');

    $this->travelTo(Carbon::parse('2026-11-01 03:00'));
    $this->artisan('savings-goals:generate-periods');
    $this->artisan('savings-goals:generate-periods');

    expect($user->notifications()->count())->toBe(1);

    $notification = $user->notifications()->first();
    expect($notification->type)->toBe(MonthlySavingsGoalClosed::class)
        ->and($notification->data)->toMatchArray([
            'savings_goal_id' => $goal->id,
            'month' => '2026-10',
            'saved' => 31000,
            'target' => 30000,
            'difference' => 1000,
            'met' => true,
        ]);

    $row = app(NotificationFeed::class)->page($user)[0];
    expect($row['kind'])->toBe('monthly_savings_goal')
        ->and($row['title'])->toBe('Emergency fund: October goal met')
        ->and($row['figure'])->toBe(['type' => 'money', 'value' => 1000, 'currency' => 'EUR'])
        ->and($row['url'])->toBe(route('savings-goals.show', $goal));
});

test('a missed month says by how much', function () {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $user = noticesUser();
    $goal = noticesGoal($user, ['name' => 'Japan trip']);
    noticesSave($goal, 24000, '2026-10-20');

    $this->travelTo(Carbon::parse('2026-11-01 03:00'));
    $this->artisan('savings-goals:generate-periods');

    $row = app(NotificationFeed::class)->page($user)[0];
    expect($row['title'])->toBe('Japan trip: October goal missed')
        ->and($row['figure']['value'])->toBe(-6000);
});

test('months filled in after an outage do not each ring the bell', function () {
    $this->travelTo(Carbon::parse('2026-07-15'));
    $user = noticesUser();
    noticesGoal($user);

    $this->travelTo(Carbon::parse('2026-11-02'));
    $this->artisan('savings-goals:generate-periods');

    expect($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['month'])->toBe('2026-10');
});

test('the month-end reminder goes out once, with five days left, when the goal is behind', function () {
    $this->travelTo(Carbon::parse('2026-10-20 09:00'));
    $user = noticesUser();
    $goal = noticesGoal($user);
    noticesSave($goal, 12000, '2026-10-06');

    $this->travelTo(Carbon::parse('2026-10-26 09:00'));
    $this->artisan('savings-goals:generate-periods');
    Mail::assertNothingOutgoing();

    $this->travelTo(Carbon::parse('2026-10-27 09:00'));
    $this->artisan('savings-goals:generate-periods');
    $this->travelTo(Carbon::parse('2026-10-28 09:00'));
    $this->artisan('savings-goals:generate-periods');

    Mail::assertQueuedCount(1);
    Mail::assertQueued(MonthlySavingsGoalReminderEmail::class, fn (MonthlySavingsGoalReminderEmail $mail): bool => $mail->hasTo($user->email)
        && $mail->goals === [['name' => $goal->name, 'percent' => 40]]
        && $mail->daysLeft === 5);

    expect($goal->periods()->where('month', '2026-10-01')->value('reminder_notified_at'))->not->toBeNull();
});

test('no reminder when the month is already met, the reminder is off or the goal is archived', function (Closure $setUp) {
    $this->travelTo(Carbon::parse('2026-10-15 09:00'));
    $setUp(noticesUser());

    $this->travelTo(Carbon::parse('2026-10-28 09:00'));
    $this->artisan('savings-goals:generate-periods');

    Mail::assertNothingOutgoing();
})->with([
    'met' => [function (User $user) {
        noticesSave(noticesGoal($user), 30000, '2026-10-03');
    }],
    'turned off' => [fn (User $user) => noticesGoal($user, ['notify_on_month_end_reminder' => false])],
    'archived' => [fn (User $user) => SavingsGoal::factory()->monthly()->archived()->create(['user_id' => $user->id])],
]);

test('a goal created in the last days of a month gets neither a reminder nor a verdict for it', function () {
    $this->travelTo(Carbon::parse('2026-10-29 10:00'));
    $user = noticesUser();
    noticesGoal($user);

    $this->artisan('savings-goals:generate-periods');
    $this->travelTo(Carbon::parse('2026-11-01 07:00'));
    $this->artisan('savings-goals:generate-periods');

    Mail::assertNothingOutgoing();
    expect($user->notifications()->count())->toBe(0);
});

test('a notice that fails is retried on the next run instead of being lost', function () {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $user = noticesUser();
    $goal = noticesGoal($user);
    $this->travelTo(Carbon::parse('2026-11-01 07:00'));

    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('database down'));
    $this->artisan('savings-goals:generate-periods')->assertSuccessful();

    expect($goal->periods()->where('month', '2026-10-01')->first())
        ->closed_at->not->toBeNull()
        ->closed_notified_at->toBeNull();

    Notification::swap(new ChannelManager($this->app));
    $this->artisan('savings-goals:generate-periods');

    expect($user->notifications()->count())->toBe(1);
});

test('deleting a goal takes its notices out of the bell', function () {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $user = noticesUser();
    $goal = noticesGoal($user);
    $this->travelTo(Carbon::parse('2026-11-01 07:00'));
    $this->artisan('savings-goals:generate-periods');

    $this->actingAs($user)->delete("/savings-goals/{$goal->id}");

    expect($user->notifications()->count())->toBe(0);
});

test('the reminder email lists each goal with its progress, never an amount', function () {
    $this->travelTo(Carbon::parse('2026-10-26 09:00'));
    $user = noticesUser();

    $mail = new MonthlySavingsGoalReminderEmail($user, '2026-10', [
        ['name' => 'Emergency fund', 'percent' => 40],
        ['name' => 'Japan <trip>', 'percent' => 75],
    ], 1, []);
    $html = $mail->render();

    expect($html)->toContain('1 day left in October')
        ->toContain('these monthly goals are still short of their October target')
        ->toContain('Emergency fund</strong>: you are at 40% of it.')
        ->toContain('Japan &lt;trip&gt;')
        ->not->toContain('€')
        ->and($mail->envelope()->subject)->toBe("1 day left to reach this month's savings targets");
});

test('one reminder per user lists every goal that is behind', function () {
    $this->travelTo(Carbon::parse('2026-10-15 09:00'));
    $user = noticesUser();
    $fund = noticesGoal($user, ['name' => 'Fund']);
    $trip = noticesGoal($user, ['name' => 'Trip']);
    $done = noticesGoal($user, ['name' => 'Done']);
    noticesSave($done, 30000, '2026-10-05');
    noticesGoal(noticesUser());

    $this->travelTo(Carbon::parse('2026-10-28 09:00'));
    $this->artisan('savings-goals:generate-periods');
    $this->artisan('savings-goals:generate-periods');

    Mail::assertQueuedCount(2);
    Mail::assertQueued(MonthlySavingsGoalReminderEmail::class, fn (MonthlySavingsGoalReminderEmail $mail): bool => $mail->hasTo($user->email)
        && array_column($mail->goals, 'name') === ['Fund', 'Trip']);

    expect($fund->periods()->sole()->reminder_notified_at)->not->toBeNull()
        ->and($trip->periods()->sole()->reminder_notified_at)->not->toBeNull()
        ->and($done->periods()->sole()->reminder_notified_at)->toBeNull();
});

test('a reminder whose delivery gives up is sent again on the next run', function () {
    $this->travelTo(Carbon::parse('2026-10-15 09:00'));
    $user = noticesUser();
    $goal = noticesGoal($user);

    $this->travelTo(Carbon::parse('2026-10-28 09:00'));
    $this->artisan('savings-goals:generate-periods');

    $mail = null;
    Mail::assertQueued(MonthlySavingsGoalReminderEmail::class, function (MonthlySavingsGoalReminderEmail $queued) use (&$mail): bool {
        $mail = $queued;

        return true;
    });
    expect($goal->periods()->sole()->reminder_notified_at)->not->toBeNull();

    // What SendQueuedMailable does once its retries run out.
    $mail->failed(new RuntimeException('smtp down'));

    expect($goal->periods()->sole()->reminder_notified_at)->toBeNull();

    $this->artisan('savings-goals:generate-periods');
    Mail::assertQueuedCount(2);
});

test('the reminder waits for the morning run, not the one just after midnight', function () {
    $this->travelTo(Carbon::parse('2026-10-15 09:00'));
    $user = noticesUser();
    noticesGoal($user);

    $this->travelTo(Carbon::parse('2026-10-28 00:05'));
    $this->artisan('savings-goals:generate-periods');
    Mail::assertNothingOutgoing();

    $this->travelTo(Carbon::parse('2026-10-28 07:00'));
    $this->artisan('savings-goals:generate-periods');
    Mail::assertQueuedCount(1);
});

test('a share-of-income goal with no income yet gets no reminder', function () {
    $this->travelTo(Carbon::parse('2026-10-15 09:00'));
    $user = noticesUser();
    noticesGoal($user, ['monthly_target_type' => 'income_rate', 'monthly_target_amount' => null, 'monthly_target_rate' => 20]);

    $this->travelTo(Carbon::parse('2026-10-28 09:00'));
    $this->artisan('savings-goals:generate-periods');

    Mail::assertNothingOutgoing();
});

test('a new monthly goal starts with the user\'s reminder default', function (bool $default) {
    $user = noticesUser();
    UserSetting::query()->updateOrCreate(['user_id' => $user->id], ['savings_goal_notify_on_month_end_reminder' => $default]);

    $this->actingAs($user)->post('/savings-goals', [
        'name' => 'Emergency fund',
        'kind' => 'monthly',
        'monthly_target_type' => 'amount',
        'monthly_target_amount' => 30000,
    ])->assertSessionHasNoErrors();

    expect(SavingsGoal::query()->where('user_id', $user->id)->value('notify_on_month_end_reminder'))->toBe($default);
})->with([true, false]);

test('the settings page lists the default and each running monthly goal, and both can be changed', function () {
    $user = noticesUser();
    $goal = noticesGoal($user, ['name' => 'Emergency fund']);
    SavingsGoal::factory()->monthly()->archived()->create(['user_id' => $user->id]);
    SavingsGoal::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/settings/notifications')
        ->assertInertia(fn ($page) => $page
            ->where('savingsGoalReminderDefault', true)
            ->has('monthlySavingsGoals', 1)
            ->where('monthlySavingsGoals.0.id', $goal->id)
        );

    $this->actingAs($user)->patch('/settings/notifications', ['notifications' => ['savings_goal_month_end_reminder' => false]]);
    $this->actingAs($user)->patch("/settings/notifications/savings-goals/{$goal->id}", ['notify_on_month_end_reminder' => false]);

    expect($user->setting()->first()->savings_goal_notify_on_month_end_reminder)->toBeFalse()
        ->and($goal->fresh()->notify_on_month_end_reminder)->toBeFalse();
});

test('nobody can change the reminder of someone else\'s goal', function () {
    $goal = noticesGoal(noticesUser());

    $this->actingAs(noticesUser())
        ->patch("/settings/notifications/savings-goals/{$goal->id}", ['notify_on_month_end_reminder' => false])
        ->assertForbidden();
});
