<?php

use App\Enums\AccountType;
use App\Features\Forecast;
use App\Jobs\MatchTransactionsToForecast;
use App\Listeners\MatchTransactionToForecast;
use App\Models\Account;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\Support\FullImportFixtures as Fixtures;

/**
 * The listener and the bulk job, as transactions are booked, edited, deleted
 * and imported. The queue runs in line in tests, so a booked transaction is
 * matched by the time it is created.
 */
function forecastRent(Account $account): ForecastItem
{
    return ForecastItem::factory()->amount(-90000)->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'anchor_date' => '2026-01-01',
        'date_window_days' => 3,
        'amount_tolerance' => 500,
        'match_rules_json' => ['in' => ['alquiler', ['var' => 'description']]],
    ]);
}

function forecastListenerQueued(): int
{
    return Queue::pushed(CallQueuedListener::class)
        ->filter(fn (CallQueuedListener $job): bool => $job->class === MatchTransactionToForecast::class)
        ->count();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->checking = Account::factory()->create(['user_id' => $this->user->id, 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    $this->rent = forecastRent($this->checking);
});

describe('with the Forecast flag on', function () {
    beforeEach(fn () => Feature::for($this->user)->activate(Forecast::class));

    it('matches a transaction when it is booked', function () {
        $payment = forecastCharge($this->checking, '2026-11-01', -90000, 'ALQUILER NOVIEMBRE');

        expect(ForecastMatch::query()->where('transaction_id', $payment->id)->value('forecast_item_id'))->toBe($this->rent->id);
    });

    it('matches again when an edit changes what the match reads', function () {
        $payment = forecastCharge($this->checking, '2026-11-01', -90000, 'BIZUM');
        expect(ForecastMatch::query()->count())->toBe(0);

        $payment->update(['description' => 'ALQUILER NOVIEMBRE']);
        expect(ForecastMatch::query()->where('transaction_id', $payment->id)->exists())->toBeTrue();

        $payment->update(['amount' => -120000]);
        expect(ForecastMatch::query()->count())->toBe(0);
    });

    it('queues nothing for an edit outside what a match reads', function () {
        $payment = forecastCharge($this->checking, '2026-11-01', -90000, 'ALQUILER NOVIEMBRE');
        Queue::fake();

        $payment->update(['external_transaction_id' => 'REF-1']);

        expect(forecastListenerQueued())->toBe(0);
    });

    it('drops the match when the transaction is deleted', function () {
        $payment = forecastCharge($this->checking, '2026-11-01', -90000, 'ALQUILER NOVIEMBRE');

        $payment->delete();

        expect(ForecastMatch::query()->count())->toBe(0);
    });

    it('queues nothing for a user without forecast lines', function () {
        $other = User::factory()->create();
        Feature::for($other)->activate(Forecast::class);
        $account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $other->id, 'currency_code' => 'EUR']);
        Queue::fake();

        forecastCharge($account, '2026-11-01', -90000, 'ALQUILER');

        expect(forecastListenerQueued())->toBe(0);
    });

    it('matches a batch, leaving what already has a match alone', function () {
        $matched = forecastCharge($this->checking, '2026-11-01', -90000, 'ALQUILER NOVIEMBRE');
        $original = ForecastMatch::query()->where('transaction_id', $matched->id)->value('id');
        $unmatched = Transaction::withoutEvents(fn () => forecastCharge($this->checking, '2026-12-01', -90000, 'ALQUILER DICIEMBRE'));

        MatchTransactionsToForecast::dispatchSync([$matched->id, $unmatched->id]);
        MatchTransactionsToForecast::dispatchSync([$matched->id, $unmatched->id]);

        expect(ForecastMatch::query()->where('transaction_id', $matched->id)->value('id'))->toBe($original)
            ->and(ForecastMatch::query()->where('transaction_id', $unmatched->id)->value('occurrence_date')->toDateString())->toBe('2026-12-01')
            ->and(ForecastMatch::query()->count())->toBe(2);
    });

    it('matches the rows a full import writes in one job per chunk, not per row', function () {
        Queue::fake([MatchTransactionsToForecast::class, CallQueuedListener::class]);

        Fixtures::run($this, $this->user, Fixtures::plan([['key' => 'a0', 'action' => 'map', 'target_account_id' => $this->checking->id]]), [
            Fixtures::row('a0', '2026-11-01', -90000, 'ALQUILER NOVIEMBRE'),
            Fixtures::row('a0', '2026-11-02', -1500, 'CAFE'),
        ]);

        Queue::assertPushed(MatchTransactionsToForecast::class, 1);
        Queue::assertPushed(MatchTransactionsToForecast::class, fn (MatchTransactionsToForecast $job): bool => count($job->transactionIds) === 2);
        expect(forecastListenerQueued())->toBe(0);
    });
});

describe('with the Forecast flag off', function () {
    it('queues nothing and stores nothing as transactions are booked, edited and deleted', function () {
        Queue::fake();

        $payment = forecastCharge($this->checking, '2026-11-01', -90000, 'ALQUILER NOVIEMBRE');
        $payment->update(['amount' => -90100]);
        $payment->delete();

        expect(forecastListenerQueued())->toBe(0)
            ->and(ForecastMatch::query()->count())->toBe(0);
    });

    it('does not resolve the flag for a user without forecast lines', function () {
        $other = User::factory()->create();
        $account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $other->id, 'currency_code' => 'EUR']);

        forecastCharge($account, '2026-11-01', -90000, 'ALQUILER');

        expect(DB::table('features')->where('name', Forecast::class)->where('scope', 'like', '%'.$other->id)->exists())->toBeFalse();
    });

    it('matches nothing in the bulk job', function () {
        $payment = forecastCharge($this->checking, '2026-11-01', -90000, 'ALQUILER NOVIEMBRE');

        MatchTransactionsToForecast::dispatchSync([$payment->id]);

        expect(ForecastMatch::query()->count())->toBe(0);
    });

    it('queues no bulk matching for an import', function () {
        Queue::fake([MatchTransactionsToForecast::class, CallQueuedListener::class]);

        Fixtures::run($this, $this->user, Fixtures::plan([['key' => 'a0', 'action' => 'map', 'target_account_id' => $this->checking->id]]), [
            Fixtures::row('a0', '2026-11-01', -90000, 'ALQUILER NOVIEMBRE'),
        ]);

        Queue::assertNotPushed(MatchTransactionsToForecast::class);
    });
});
