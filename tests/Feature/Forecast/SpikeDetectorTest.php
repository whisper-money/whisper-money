<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastMatch;
use App\Models\User;
use App\Services\Forecast\Data\SpikeCandidate;
use App\Services\Forecast\SpikeDetector;

/**
 * @return list<SpikeCandidate>
 */
function spikesFor(User $user): array
{
    return app(SpikeDetector::class)->detect($user, $user->current_space_id, '2026-11-26');
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->food = forecastExpenseCategory($this->user);
    $this->groceries = forecastExpenseCategory($this->user, $this->food);

    foreach (['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'] as $month) {
        foreach ([3, 10, 17, 24] as $day) {
            forecastCharge($this->account, sprintf('%s-%02d', $month, $day), -8000 - $day * 10, 'MERCADONA', $this->groceries);
        }
    }
});

it('asks about a charge far above what its parent category usually sees', function () {
    $spike = forecastCharge($this->account, '2026-11-20', -120000, 'EL CORTE INGLES ALIMENTACION', $this->groceries);
    forecastCharge($this->account, '2026-11-21', -9000, 'MERCADONA', $this->groceries);

    $spikes = spikesFor($this->user);

    expect($spikes)->toHaveCount(1)
        ->and($spikes[0]->transactionId)->toBe($spike->id)
        ->and($spikes[0]->categoryId)->toBe($this->food->id)
        ->and($spikes[0]->amount)->toBe(120000)
        ->and($spikes[0]->categoryMonthlyMedian)->toBe(32540)
        ->and($spikes[0]->categoryP95)->toBe(8240);
});

it('needs the charge to be above three times the monthly median too', function () {
    forecastCharge($this->account, '2026-11-20', -90000, 'COMPRA GRANDE', $this->groceries);

    expect(spikesFor($this->user))->toBe([]);
});

it('does not ask about what was already answered or planned', function () {
    $excluded = forecastCharge($this->account, '2026-11-20', -120000, 'CATERING BODA', $this->groceries);
    ForecastExcludedTransaction::factory()->create(['transaction_id' => $excluded->id]);
    $matched = forecastCharge($this->account, '2026-11-22', -150000, 'CESTA NAVIDAD', $this->groceries);
    ForecastMatch::factory()->create(['transaction_id' => $matched->id]);

    expect(spikesFor($this->user))->toBe([]);
});

it('does not judge a category without enough history, nor income or transfers', function () {
    $travel = forecastExpenseCategory($this->user);
    forecastCharge($this->account, '2026-08-01', -5000, 'RENFE', $travel);
    forecastCharge($this->account, '2026-11-20', -200000, 'VIAJE JAPON', $travel);
    forecastCharge($this->account, '2026-11-20', 300000, 'DEVOLUCION', $this->groceries);
    $transfer = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Transfer]);
    forecastCharge($this->account, '2026-11-20', -500000, 'TRASPASO', $transfer);

    expect(spikesFor($this->user))->toBe([]);
});
