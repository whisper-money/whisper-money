<?php

use App\Console\Commands\RefreshForecasts;
use App\Enums\AccountType;
use App\Enums\ForecastAlertLevel;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Features\Forecast;
use App\Mail\ForecastAlertEmail;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastAlert;
use App\Models\ForecastItem;
use App\Models\SuppressedEmailAddress;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\Forecast\ForecastAlertNotifier;
use App\Services\Forecast\ForecastAlertService;
use App\Services\Forecast\Refresh\EvaluateForecastAlerts;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * A Pro user whose checking account starts November with 1.000,00 and pays
 * 300,00 rent on the 15th: it drops below zero in February 2027, the fourth
 * month of the forecast, and stays there to its end (November 2027).
 */
function alertsUser(bool $pro = true): User
{
    config(['subscriptions.enabled' => true]);
    $factory = User::factory()->onboarded();
    $user = ($pro ? $factory->subscribed() : $factory)->create(['timezone' => 'Europe/Madrid', 'currency_code' => 'EUR', 'locale' => 'es']);
    Feature::for($user)->activate(Forecast::class);
    $account = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking, 'currency_code' => 'EUR', 'name' => 'Cuenta Nómina Secreta']);
    AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-11-01', 'balance' => 100_000]);
    ForecastItem::factory()->amount(-30_000)->create(['user_id' => $user->id, 'account_id' => $account->id, 'anchor_date' => '2026-01-15']);

    return $user;
}

function alertsAccount(User $user): Account
{
    return Account::query()->where('user_id', $user->id)->firstOrFail();
}

/**
 * @return list<string>
 */
function openAlertMonths(User $user): array
{
    return ForecastAlert::query()
        ->where('user_id', $user->id)
        ->whereNull('resolved_at')
        ->orderBy('month')
        ->get()
        ->map(fn (ForecastAlert $alert): string => $alert->month->format('Y-m'))
        ->all();
}

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-10 12:00:00');
});

it('stores a strong alert for each month short and tells about them once', function () {
    $user = alertsUser();

    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(openAlertMonths($user))->toBe(['2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2027-07', '2027-08', '2027-09', '2027-10', '2027-11'])
        ->and(ForecastAlert::query()->where('level', '!=', ForecastAlertLevel::Strong)->count())->toBe(0)
        ->and(ForecastAlert::query()->whereNull('notified_at')->count())->toBe(0);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(ForecastAlertEmail::class, fn (ForecastAlertEmail $mail): bool => $mail->hasTo($user->email)
        && $mail->month === '2027-02'
        && $mail->monthsAway === 3
        && ! $mail->belowMinimum
        && count($mail->alertIds) === 10);

    expect($user->notifications()->count())->toBe(1);
    $row = app(NotificationFeed::class)->page($user)[0];
    expect($row['kind'])->toBe('forecast_alert')
        ->and($row['url'])->toBe(route('forecast.index'));
});

it('sends nothing again while the problem stays', function () {
    $user = alertsUser();

    $this->artisan('forecast:refresh')->assertSuccessful();
    $this->travelTo('2026-11-10 13:00:00');
    $this->artisan('forecast:refresh')->assertSuccessful();

    Mail::assertQueuedCount(1);
    expect($user->notifications()->count())->toBe(1)
        ->and(ForecastAlert::query()->count())->toBe(10);
});

it('resolves an alert that goes away, and tells again when it comes back', function () {
    $user = alertsUser();
    $this->artisan('forecast:refresh')->assertSuccessful();

    $bonus = ForecastItem::factory()->amount(500_000)->create([
        'user_id' => $user->id,
        'account_id' => alertsAccount($user)->id,
        'anchor_date' => '2027-01-20',
        'frequency' => ForecastFrequency::Once,
        'kind' => ForecastItemKind::OneOff,
    ]);
    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(openAlertMonths($user))->toBe([])
        ->and(ForecastAlert::query()->whereNotNull('resolved_at')->count())->toBe(10);

    $bonus->update(['archived_at' => now()]);
    // A week later: November's rent is behind, so February no longer falls short.
    $this->travelTo('2026-11-18 14:00:00');
    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(openAlertMonths($user))->toHaveCount(9)
        ->and(ForecastAlert::query()->count())->toBe(10)
        ->and(ForecastAlert::query()->whereNull('resolved_at')->pluck('first_seen_at')->map->toDateTimeString()->unique()->all())->toBe(['2026-11-18 14:00:00']);
    Mail::assertQueuedCount(2);
    expect($user->notifications()->count())->toBe(2);
});

it('reopens a problem that flickers back within a week without telling again', function () {
    $user = alertsUser();
    $this->artisan('forecast:refresh')->assertSuccessful();

    $bonus = ForecastItem::factory()->amount(500_000)->create([
        'user_id' => $user->id,
        'account_id' => alertsAccount($user)->id,
        'anchor_date' => '2027-01-20',
        'frequency' => ForecastFrequency::Once,
        'kind' => ForecastItemKind::OneOff,
    ]);
    $this->artisan('forecast:refresh')->assertSuccessful();
    $bonus->update(['archived_at' => now()]);
    $this->travelTo('2026-11-13 09:00:00');
    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(openAlertMonths($user))->toHaveCount(10)
        ->and(ForecastAlert::query()->value('first_seen_at')->toDateTimeString())->toBe('2026-11-10 12:00:00');
    Mail::assertQueuedCount(1);
    expect($user->notifications()->count())->toBe(1);
});

it('tells about a month that turns short later, without repeating the others', function () {
    $user = alertsUser();
    $this->artisan('forecast:refresh')->assertSuccessful();

    ForecastItem::factory()->amount(-50_000)->create([
        'user_id' => $user->id,
        'account_id' => alertsAccount($user)->id,
        'anchor_date' => '2027-01-03',
        'frequency' => ForecastFrequency::Once,
        'kind' => ForecastItemKind::OneOff,
    ]);
    $this->artisan('forecast:refresh')->assertSuccessful();

    Mail::assertQueuedCount(2);
    Mail::assertQueued(ForecastAlertEmail::class, fn (ForecastAlertEmail $mail): bool => $mail->month === '2027-01' && count($mail->alertIds) === 1);
});

it('counts only the month in progress and the next for a user without Pro', function () {
    $user = alertsUser(pro: false);

    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(openAlertMonths($user))->toBe([]);
    Mail::assertNothingQueued();

    // Rent that empties the account in December, the month they can see.
    ForecastItem::factory()->amount(-80_000)->create(['user_id' => $user->id, 'account_id' => alertsAccount($user)->id, 'anchor_date' => '2026-12-02', 'frequency' => ForecastFrequency::Once, 'kind' => ForecastItemKind::OneOff]);
    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(openAlertMonths($user))->toBe(['2026-12']);
    Mail::assertQueued(ForecastAlertEmail::class, fn (ForecastAlertEmail $mail): bool => $mail->month === '2026-12' && $mail->monthsAway === 1);
});

it('says when the balance stays above zero but under the wanted minimum', function () {
    $user = alertsUser();
    ForecastAccountSetting::factory()->create(['account_id' => alertsAccount($user)->id, 'minimum_balance' => 50_000]);

    $this->artisan('forecast:refresh')->assertSuccessful();

    Mail::assertQueued(ForecastAlertEmail::class, fn (ForecastAlertEmail $mail): bool => $mail->month === '2026-12' && $mail->belowMinimum);
});

it('rings the bell but sends no email when the user turned the email off', function () {
    $user = alertsUser();
    UserSetting::query()->updateOrCreate(['user_id' => $user->id], ['forecast_notify_email' => false]);

    $this->artisan('forecast:refresh')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($user->notifications()->count())->toBe(1)
        ->and(ForecastAlert::query()->whereNull('notified_at')->count())->toBe(0);
});

it('sends no email to an address that cannot receive it', function () {
    $user = alertsUser();
    SuppressedEmailAddress::factory()->create(['email' => $user->email]);

    $this->artisan('forecast:refresh')->assertSuccessful();

    Mail::assertNothingQueued();
    expect($user->notifications()->count())->toBe(1);
});

it('neither emails nor rings for a soft alert', function () {
    $user = alertsUser();
    ForecastAlert::query()->create([
        'user_id' => $user->id,
        'account_id' => alertsAccount($user)->id,
        'month' => '2026-12-01',
        'level' => ForecastAlertLevel::Soft,
        'first_seen_at' => now(),
    ]);

    app(ForecastAlertNotifier::class)->notifyNew($user, []);

    Mail::assertNothingQueued();
    expect($user->notifications()->count())->toBe(0);
});

it('hands the alerts back when the email gives up, without ringing twice on the retry', function () {
    $user = alertsUser();
    $this->artisan('forecast:refresh')->assertSuccessful();

    $mail = null;
    Mail::assertQueued(ForecastAlertEmail::class, function (ForecastAlertEmail $queued) use (&$mail): bool {
        $mail = $queued;

        return true;
    });
    (new ForecastAlertEmail($mail->month, $mail->monthsAway, $mail->belowMinimum, $mail->alertIds))->failed(new RuntimeException('SMTP down'));

    expect(ForecastAlert::query()->whereNull('notified_at')->count())->toBe(10);

    $this->artisan('forecast:refresh')->assertSuccessful();

    Mail::assertQueuedCount(2);
    expect($user->notifications()->count())->toBe(1);
});

it('releases the claim when sending throws', function () {
    $user = alertsUser();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Queue down'));

    expect(fn () => app(ForecastAlertService::class)->refresh($user))->toThrow(RuntimeException::class);
    expect(ForecastAlert::query()->whereNull('notified_at')->count())->toBe(10);
});

it('resolves the open alerts of a user who has no forecast line left', function () {
    $user = alertsUser();
    $this->artisan('forecast:refresh')->assertSuccessful();

    ForecastItem::query()->where('user_id', $user->id)->update(['archived_at' => now()]);
    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(openAlertMonths($user))->toBe([]);
});

it('does nothing for a user without the flag', function () {
    $user = alertsUser();
    Feature::for($user)->deactivate(Forecast::class);

    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(ForecastAlert::query()->count())->toBe(0)
        ->and($user->notifications()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('writes no amount and no account name in the email or the bell', function () {
    $user = alertsUser();
    $this->artisan('forecast:refresh')->assertSuccessful();

    $mail = null;
    Mail::assertQueued(ForecastAlertEmail::class, function (ForecastAlertEmail $queued) use (&$mail): bool {
        $mail = $queued;

        return true;
    });

    app()->setLocale('es');
    $html = $mail->render();
    // What a reader sees: the body, without its styles and the footer's year.
    $text = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(style|head)\b.*?</\1>#s', '', $html))));
    $row = app(NotificationFeed::class)->page($user)[0];

    expect($mail->envelope()->subject)->toBe('Una de tus cuentas podría quedarse corta en febrero')
        ->and($text)->toContain('Tu previsión ha detectado un mes ajustado')
        ->and($text)->toContain('podría bajar de cero en')
        ->and($text)->toContain('febrero de 2027')
        ->and($text)->toContain('Faltan unos 3 meses')
        ->and($html)->toContain('Ver mi previsión')
        ->and($html)->toContain(route('forecast.index'))
        ->and($html)->toContain('Gestionar avisos de previsión')
        ->and($html)->toContain(route('notifications.index'));

    foreach ([$text, $row['title'], (string) $row['body']] as $written) {
        expect($written)->not->toContain('Cuenta Nómina Secreta')
            ->and(preg_match_all('/\d+(?:[.,]\d+)*|€/u', $written, $figures) >= 0)->toBeTrue()
            ->and(array_values(array_diff($figures[0], ['3', '2027', date('Y')])))->toBe([]);
    }
});

it('is one of the hourly refresh steps', function () {
    $steps = (new ReflectionClassConstant(RefreshForecasts::class, 'HOURLY_STEPS'))->getValue();

    expect($steps)->toContain(EvaluateForecastAlerts::class);
});

it('never raises an alert for a credit card without a wanted minimum, by the page\'s rule', function () {
    $user = alertsUser();
    alertsAccount($user)->update(['type' => AccountType::CreditCard]);

    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(ForecastAlert::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});
