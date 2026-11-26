<?php

use App\Enums\ForecastFrequency;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastItem;
use App\Models\ForecastMatch;
use App\Models\User;
use App\Services\Forecast\Data\Occurrence;
use App\Services\Forecast\Data\ProjectionAccount;
use App\Services\Forecast\ForecastPlanBuilder;
use App\Services\Forecast\ForecastProjector;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->checking = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'name' => 'A checking']);
    $this->savings = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'name' => 'B savings']);
    AccountBalance::factory()->create(['account_id' => $this->checking->id, 'balance_date' => '2026-11-01', 'balance' => 150000]);
    AccountBalance::factory()->create(['account_id' => $this->savings->id, 'balance_date' => '2026-10-15', 'balance' => 900000]);
    ForecastAccountSetting::factory()->create(['account_id' => $this->savings->id, 'annual_rate_bps' => 250, 'minimum_balance' => 500000]);

    $this->rent = ForecastItem::factory()->amount(-80000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->checking->id,
        'frequency' => ForecastFrequency::Monthly,
        'anchor_date' => '2026-01-01',
    ]);
    $this->saving = ForecastItem::factory()->transfer($this->savings)->amount(-20000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->checking->id,
        'frequency' => ForecastFrequency::Monthly,
        'anchor_date' => '2026-01-05',
    ]);
});

it('gathers the accounts, their lines and their estimates into the projector\'s input', function () {
    $food = forecastExpenseCategory($this->user);
    foreach (['2026-08-12', '2026-09-12', '2026-10-12'] as $date) {
        forecastCharge($this->checking, $date, -30000, 'MERCADONA', $food);
    }

    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id, horizonMonths: 3, startDate: '2026-11-10');
    $input = $plan->input;

    expect($input->startDate)->toBe('2026-11-10')
        ->and($input->horizonMonths)->toBe(3)
        ->and($input->accounts)->toEqual([
            new ProjectionAccount($this->checking->id, 150000),
            new ProjectionAccount($this->savings->id, 900000, 250, 500000),
        ])
        ->and(array_map(fn (Occurrence $occurrence): string => $occurrence->itemId.'@'.$occurrence->date, $input->occurrences))->toBe([
            $this->rent->id.'@2026-12-01',
            $this->saving->id.'@2026-12-05',
            $this->rent->id.'@2027-01-01',
            $this->saving->id.'@2027-01-05',
        ])
        ->and(array_map(fn ($estimate) => [$estimate->accountId, $estimate->month, $estimate->amount], $input->estimates))->toBe([
            [$this->checking->id, '2026-11', 30000],
            [$this->checking->id, '2026-12', 30000],
            [$this->checking->id, '2027-01', 30000],
        ])
        ->and($plan->categoryEstimates)->toHaveCount(3);

    $projection = app(ForecastProjector::class)->project($input);
    expect($projection['accounts'])->toHaveCount(2);
});

it('narrows to the selected accounts and keeps the transfers that reach them', function () {
    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id, [$this->savings->id], 3, '2026-11-10');

    expect(array_map(fn (ProjectionAccount $account): string => $account->id, $plan->input->accounts))->toBe([$this->savings->id])
        ->and(array_unique(array_map(fn (Occurrence $occurrence): string => $occurrence->itemId, $plan->input->occurrences)))->toBe([$this->saving->id]);
});

it('leaves out the accounts the user excluded, archived lines and other users\' lines, but keeps the transfers leaving to an excluded account', function () {
    ForecastAccountSetting::query()->where('account_id', $this->savings->id)->update(['included' => false]);
    ForecastItem::factory()->archived()->amount(-5000)->create(['user_id' => $this->user->id, 'account_id' => $this->checking->id, 'anchor_date' => '2026-01-15']);
    $partner = User::factory()->create();
    $partnerAccount = Account::factory()->create(['user_id' => $partner->id, 'space_id' => $this->user->current_space_id]);
    ForecastItem::factory()->amount(-5000)->create(['user_id' => $partner->id, 'account_id' => $partnerAccount->id, 'anchor_date' => '2026-01-15']);

    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id, horizonMonths: 2, startDate: '2026-11-10');

    expect(array_map(fn (ProjectionAccount $account): string => $account->id, $plan->input->accounts))->toBe([$this->checking->id])
        ->and(collect($plan->input->occurrences)->pluck('itemId')->unique()->sort()->values()->all())->toBe(collect([$this->rent->id, $this->saving->id])->sort()->values()->all());
});

it('keeps a transfer from an excluded account into an included one', function () {
    ForecastAccountSetting::query()->where('account_id', $this->savings->id)->update(['included' => false]);
    $back = ForecastItem::factory()->transfer($this->checking)->amount(-10000)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->savings->id,
        'anchor_date' => '2026-01-20',
    ]);

    $plan = app(ForecastPlanBuilder::class)->build($this->user, $this->user->current_space_id, horizonMonths: 2, startDate: '2026-11-10');

    expect(collect($plan->input->occurrences)->pluck('itemId')->contains($back->id))->toBeTrue();
});

it('runs nothing on its own, so a user without the Forecast flag sees no change', function () {
    expect(Feature::for($this->user)->active(Forecast::class))->toBeFalse();
    Queue::fake();

    forecastMonthlyCharges($this->checking, '2026-05-03', 6, -6000, 'RECIBO IBERDROLA');

    expect(Queue::pushed(CallQueuedListener::class)->filter(fn (CallQueuedListener $job): bool => str_contains($job->class, 'Forecast')))->toBeEmpty()
        ->and(ForecastItem::query()->count())->toBe(2)
        ->and(ForecastMatch::query()->count())->toBe(0)
        ->and(ForecastExcludedTransaction::query()->count())->toBe(0);
});
