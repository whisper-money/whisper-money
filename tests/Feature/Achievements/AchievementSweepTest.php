<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Jobs\Drip\SendAchievementsEmailJob;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\AchievementsWelcome;
use App\Notifications\AchievementUnlocked;
use App\Services\Achievements\Awarder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/*
 * The sweep, end to end: real transactions in, rows out, and who gets told.
 *
 * The rules themselves are covered against a hand-built history elsewhere. What
 * matters here is that a life read out of the database lands on the right
 * months, that a medal is written once and never again, and that a backfill
 * arrives as one welcome rather than as twenty separate congratulations.
 */

function reader(): User
{
    return User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
}

/**
 * A month with money coming in and some of it set aside.
 */
function recordMonth(User $user, string $month, int $income, int $saved): void
{
    $account = $user->accounts()->first() ?? Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'currency_code' => 'EUR',
    ]);

    $categories = collect([CategoryType::Income, CategoryType::Savings])
        ->mapWithKeys(fn (CategoryType $type): array => [$type->value => Category::query()
            ->firstOrCreate(
                ['user_id' => $user->id, 'type' => $type, 'name' => $type->value],
                ['space_id' => $user->activeSpace()->id],
            )]);

    $date = $month.'-05';

    Transaction::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'account_id' => $account->id,
        'category_id' => $categories[CategoryType::Income->value]->id,
        'transaction_date' => $date,
        'amount' => $income,
        'currency_code' => 'EUR',
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'account_id' => $account->id,
        'category_id' => $categories[CategoryType::Savings->value]->id,
        'transaction_date' => $date,
        'amount' => -$saved,
        'currency_code' => 'EUR',
    ]);
}

/**
 * The rate provider as it really answers: a rate map from
 * `achievements.rates_from` on, and a 404 for every date before it, which is
 * what a month end in 2001 gets asked for and always will.
 */
function fakeCoveredCurrencyApi(): void
{
    $from = (string) config('achievements.rates_from');

    Http::fake(function (Request $request) use ($from) {
        if (! preg_match('#(\d{4}-\d{2})-\d{2}/(?:v1/)?currencies/([a-z0-9]+)\.min\.json#i', $request->url(), $matches)) {
            return null;
        }

        if ($matches[1] < $from) {
            return Http::response([], 404);
        }

        return Http::response([strtolower($matches[2]) => ['btc' => 0.00001, 'eur' => 1.0, 'usd' => 1.1]]);
    });
}

/**
 * A reader holding a currency other than the one their medals are measured in.
 */
function withForeignAccount(User $user): void
{
    Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'currency_code' => 'BTC',
    ]);
}

it('dates a medal to the month it happened, not to the day it was noticed', function (): void {
    $user = reader();
    recordMonth($user, now()->subMonths(8)->format('Y-m'), 300000, 150000);

    app(Awarder::class)->sweep($user);

    $first = $user->achievements()->where('key', 'transactions.1')->firstOrFail();

    expect($first->achieved_on->format('Y-m'))->toBe(now()->subMonths(8)->format('Y-m'))
        ->and($user->achievements()->where('key', 'monthly_saving.2')->first()?->value)->toBe(150000);
});

it('announces a backfill once instead of medal by medal', function (): void {
    Notification::fake();
    $user = reader();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 150000);

    $recorded = app(Awarder::class)->sweep($user);

    expect($recorded->count())->toBeGreaterThan(1);

    Notification::assertSentTo($user, AchievementsWelcome::class, function (AchievementsWelcome $notification) use ($recorded): bool {
        return $notification->count === $recorded->count();
    });
    Notification::assertNotSentTo($user, AchievementUnlocked::class);
});

it('gives every later medal a row of its own, and the day one email', function (): void {
    Notification::fake();
    Queue::fake();
    $user = reader();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 15000);

    app(Awarder::class)->sweep($user);

    // A better month arrives, clearing the next rung.
    recordMonth($user, now()->subMonths(2)->format('Y-m'), 400000, 260000);
    $recorded = app(Awarder::class)->sweep($user);

    expect($recorded)->not->toBeEmpty();

    Notification::assertSentToTimes($user, AchievementUnlocked::class, $recorded->count());
    Queue::assertPushed(SendAchievementsEmailJob::class, 1);
});

it('records a medal once, however many times the sweep runs', function (): void {
    $user = reader();
    recordMonth($user, now()->subMonths(4)->format('Y-m'), 300000, 150000);

    $first = app(Awarder::class)->sweep($user)->count();

    expect(app(Awarder::class)->sweep($user))->toBeEmpty()
        ->and($user->achievements()->count())->toBe($first);
});

it('keeps a medal after the money that earned it is gone', function (): void {
    $user = reader();
    recordMonth($user, now()->subMonths(5)->format('Y-m'), 300000, 150000);
    app(Awarder::class)->sweep($user);

    $before = $user->achievements()->pluck('key')->sort()->values();

    $user->transactions()->delete();
    app(Awarder::class)->sweep($user);

    expect($user->achievements()->pluck('key')->sort()->values())->toEqual($before);
});

it('sends no email to a reader who asked for none', function (): void {
    Queue::fake();
    $user = reader();
    $user->setting()->updateOrCreate(['user_id' => $user->id], ['notify_achievements' => false]);
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 15000);
    app(Awarder::class)->sweep($user);

    recordMonth($user, now()->subMonths(2)->format('Y-m'), 400000, 260000);
    app(Awarder::class)->sweep($user);

    Queue::assertNotPushed(SendAchievementsEmailJob::class);
});

it('finds nothing for a reader with no transactions', function (): void {
    expect(app(Awarder::class)->sweep(reader()))->toBeEmpty();
});

it('measures a reader in a currency it has no ladder for against the fallback', function (): void {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response(['usd' => ['jpy' => 150.0, 'eur' => 0.9, 'usd' => 1.0]]),
    ]);

    $user = reader();
    $user->forceFill(['currency_code' => 'JPY'])->save();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 150000);

    app(Awarder::class)->sweep($user);

    $medal = $user->achievements()->where('key', 'monthly_saving.1')->first();

    expect($medal?->currency_code)->toBe('USD');
});

it('records nothing when a foreign balance cannot be converted', function (): void {
    // No rate for the day, and neither the CDN nor the mirror behind it can be
    // reached for one. Both hosts, because an unfaked URL is fetched for real:
    // faking only the CDN left the test asking the network for a BTC rate and
    // passing wherever that request happened to fail.
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response([], 500),
        'currency-api.pages.dev/*' => Http::response([], 500),
    ]);

    $user = reader();
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 150000);

    // A second account holding money in a currency the ladder is not in.
    Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'currency_code' => 'BTC',
    ]);

    // A milestone dated from a half-converted history would be wrong forever,
    // so the sweep reports the reader and moves on rather than guessing.
    $this->artisan('achievements:sweep', ['--user' => $user->email])
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    expect($user->achievements()->count())->toBe(0);
});

it('keeps the account menu count in step, even on a sweep that awards nothing', function (): void {
    $user = reader();
    recordMonth($user, now()->subMonths(4)->format('Y-m'), 300000, 150000);

    $awarded = app(Awarder::class)->sweep($user)->count();

    expect($user->fresh()->achievements_count)->toBe($awarded);

    // A row removed by hand is corrected by the next pass rather than leaving
    // the badge claiming a medal that is gone.
    $user->achievements()->latest('achieved_on')->limit(1)->get()->each->delete();
    app(Awarder::class)->sweep($user);

    expect($user->fresh()->achievements_count)->toBe($user->achievements()->count());
});

it('records a year of growth off a near-zero start instead of failing the whole sweep', function (): void {
    $user = reader();

    // Pinned rather than left to the factory's random type: a loan subtracts
    // from net worth and a credit card is left out of it altogether, and either
    // one would leave nothing for a growth rate to be read against.
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'currency_code' => 'EUR',
        'type' => AccountType::Checking,
    ]);

    recordMonth($user, now()->subMonths(13)->format('Y-m'), 300000, 150000);
    recordMonth($user, now()->subMonth()->format('Y-m'), 300000, 150000);

    // A few cents a year ago against an ordinary balance today. The rate that
    // comes out of that is seven figures, well past what the `percent` column
    // holds, and the insert used to take every other medal down with it.
    foreach ([13 => 100, 1 => 1092000] as $monthsAgo => $balance) {
        AccountBalance::factory()->create([
            'account_id' => $account->id,
            'balance_date' => now()->subMonths($monthsAgo)->endOfMonth()->toDateString(),
            'balance' => $balance,
        ]);
    }

    app(Awarder::class)->sweep($user);

    expect($user->achievements()->where('key', 'momentum.2')->first()?->percent)->toBe(999999.99);
});

it('reads a foreign-currency reader from the first month a rate can exist', function (): void {
    fakeCoveredCurrencyApi();

    $user = reader();

    // A statement imported from further back than any rate provider goes, and
    // a month from the covered period.
    recordMonth($user, '2001-01', 300000, 150000);
    recordMonth($user, now()->subMonths(3)->format('Y-m'), 300000, 150000);

    withForeignAccount($user);

    $this->artisan('achievements:sweep', ['--user' => $user->email])
        ->doesntExpectOutputToContain('Skipped')
        ->assertSuccessful();

    // The medals their convertible history earns, rather than nothing at all
    // every night forever because January 2001 has no rate and never will.
    // Their 2001 months are the price: no rate can date a medal to them.
    expect($user->achievements()->where('key', 'monthly_saving.2')->exists())->toBeTrue()
        ->and($user->achievements()->where('key', 'transactions.1')->firstOrFail()->achieved_on->format('Y-m'))
        ->toBe(now()->subMonths(3)->format('Y-m'));
});

it('reads the rates afresh for every reader instead of hoarding them for the run', function (): void {
    $month = now()->subMonths(3)->format('Y-m');
    $first = reader();
    $second = reader();

    foreach ([$first, $second] as $user) {
        recordMonth($user, $month, 300000, 150000);
        withForeignAccount($user);
    }

    // Every month end the sweep needs, already in the database, so a rate
    // lookup is a query and never a request: counting the queries counts the
    // lookups.
    foreach (range(1, 3) as $monthsBack) {
        ExchangeRate::factory()->create([
            'base_currency' => 'eur',
            'date' => now()->subMonths($monthsBack)->endOfMonth()->toDateString(),
            'rates' => ['btc' => 0.00001, 'eur' => 1.0, 'usd' => 1.1],
        ]);
    }

    $lookups = function (array $options): int {
        $count = 0;

        DB::listen(function ($query) use (&$count): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'exchange_rates')) {
                $count++;
            }
        });

        $this->artisan('achievements:sweep', [...$options, '--quiet-notifications' => true])->assertSuccessful();

        return $count;
    };

    $one = $lookups(['--user' => $first->email]);
    $both = $lookups([]);

    // Two readers cost two readers' worth of lookups: neither inherits the
    // rates the one before it cached, which is what keeps a run over the whole
    // member list inside the memory of one history.
    expect($one)->toBeGreaterThan(0)
        ->and($both)->toBe($one * 2);
});
