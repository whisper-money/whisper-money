<?php

use App\Enums\AccountType;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\ForecastSnapshot;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Forecast\Refresh\ForecastRefreshStep;
use App\Services\Forecast\Refresh\FreezeMonthSnapshots;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * A user with a monthly rent line, and a rent payment booked without events,
 * as if it arrived before the line was created.
 *
 * @return array{0: User, 1: Transaction}
 */
function forecastUserWithUnmatchedRent(): array
{
    $user = User::factory()->create(['timezone' => 'Europe/Madrid']);
    $account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $user->id, 'currency_code' => 'EUR']);
    ForecastItem::factory()->amount(-90000)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'anchor_date' => '2026-01-01',
        'amount_tolerance' => 500,
    ]);
    $payment = Transaction::withoutEvents(fn () => forecastCharge($account, '2026-11-01', -90000, 'ALQUILER'));

    return [$user, $payment];
}

beforeEach(fn () => $this->travelTo('2026-11-10 12:00:00'));

it('freezes the month and matches recent transactions for users with the flag', function () {
    [$user, $payment] = forecastUserWithUnmatchedRent();
    Feature::for($user)->activate(Forecast::class);

    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(ForecastSnapshot::query()->where('user_id', $user->id)->value('month')->toDateString())->toBe('2026-11-01')
        ->and(ForecastMatch::query()->where('transaction_id', $payment->id)->exists())->toBeTrue();
});

it('is safe to run again', function () {
    [$user] = forecastUserWithUnmatchedRent();
    Feature::for($user)->activate(Forecast::class);

    $this->artisan('forecast:refresh')->assertSuccessful();
    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(ForecastSnapshot::query()->count())->toBe(1)
        ->and(ForecastMatch::query()->count())->toBe(1);
});

it('leaves transactions older than the lookback alone', function () {
    [$user] = forecastUserWithUnmatchedRent();
    Feature::for($user)->activate(Forecast::class);
    $account = Account::query()->where('user_id', $user->id)->firstOrFail();
    $old = Transaction::withoutEvents(fn () => forecastCharge($account, '2026-09-01', -90000, 'ALQUILER'));

    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(ForecastMatch::query()->where('transaction_id', $old->id)->exists())->toBeFalse();
});

it('does nothing for users without the flag, and resolves it for nobody', function () {
    [$off, $payment] = forecastUserWithUnmatchedRent();
    [$deactivated] = forecastUserWithUnmatchedRent();
    Feature::for($deactivated)->deactivate(Forecast::class);

    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(ForecastSnapshot::query()->count())->toBe(0)
        ->and(ForecastMatch::query()->count())->toBe(0)
        ->and(DB::table('features')->where('scope', 'like', '%'.$off->id)->exists())->toBeFalse();
});

it('keeps going when a step fails', function () {
    [$first, $firstPayment] = forecastUserWithUnmatchedRent();
    [$second, $secondPayment] = forecastUserWithUnmatchedRent();
    Feature::for($first)->activate(Forecast::class);
    Feature::for($second)->activate(Forecast::class);
    app()->bind(FreezeMonthSnapshots::class, fn () => new class implements ForecastRefreshStep
    {
        public function run(User $user): void
        {
            throw new RuntimeException('Snapshot failed');
        }
    });

    $this->artisan('forecast:refresh')->assertSuccessful();

    expect(ForecastMatch::query()->whereIn('transaction_id', [$firstPayment->id, $secondPayment->id])->count())->toBe(2);
});

it('is scheduled hourly with a lock that expires within the hour', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'forecast:refresh'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expiresAt)->toBe(60);
});
