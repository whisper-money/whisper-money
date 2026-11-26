<?php

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastSuggestionKind;
use App\Models\Account;
use App\Models\ForecastDismissal;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\ForecastSuggestions;

/**
 * @return list<ForecastCandidate>
 */
function annualCandidates(User $user): array
{
    return app(ForecastSuggestions::class)->for($user, $user->current_space_id, '2026-11-26')['annual'];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
});

it('suggests a yearly charge seen twice a year apart, always for review', function () {
    $insurance = forecastExpenseCategory($this->user);
    $first = forecastCharge($this->account, '2025-06-10', -32000, 'MAPFRE SEGURO HOGAR POLIZA 77812', $insurance);
    $second = forecastCharge($this->account, '2026-06-08', -34500, 'Mapfre Seguro Hogar poliza 77812/2026 - 08.06', $insurance);

    $candidates = annualCandidates($this->user);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->kind)->toBe(ForecastSuggestionKind::Annual)
        ->and($candidates[0]->frequency)->toBe(ForecastFrequency::Yearly)
        ->and($candidates[0]->confidence)->toBe(ForecastConfidence::Review)
        ->and($candidates[0]->fingerprint)->toBe('mapfre seguro hogar poliza')
        ->and($candidates[0]->amount)->toBe(-33250)
        ->and($candidates[0]->anchorDay)->toBe(9)
        ->and($candidates[0]->nextDate)->toBe('2027-06-09')
        ->and($candidates[0]->categoryId)->toBe($insurance->id)
        ->and($candidates[0]->transactionIds)->toBe([$first->id, $second->id]);
});

it('suggests nothing without strict proof', function (array $charges) {
    foreach ($charges as $date => $amount) {
        forecastCharge($this->account, $date, $amount, 'IMPUESTO CIRCULACION AYTO');
    }

    expect(annualCandidates($this->user))->toBe([]);
})->with([
    'charged in between' => [['2025-06-10' => -14000, '2025-12-01' => -14000, '2026-06-09' => -14000]],
    'amount moved more than 15 %' => [['2025-06-10' => -14000, '2026-06-09' => -17000]],
    'nine months apart' => [['2025-09-10' => -14000, '2026-06-09' => -14000]],
    'fourteen months apart' => [['2025-05-10' => -14000, '2026-07-12' => -14000]],
    'seen once' => [['2026-06-09' => -14000]],
    'a third charge earlier' => [['2025-05-02' => -14000, '2025-06-10' => -14000, '2026-06-09' => -14000]],
    'under 30 a year' => [['2025-06-10' => -2500, '2026-06-09' => -2500]],
    'next one long overdue' => [['2024-11-01' => -14000, '2025-11-01' => -14000]],
]);

it('does not suggest a yearly charge without a fingerprint', function () {
    forecastCharge($this->account, '2025-06-10', -32000, '77812 / 10-06');
    forecastCharge($this->account, '2026-06-09', -32000, '90233 / 09-06');

    expect(annualCandidates($this->user))->toBe([]);
});

it('does not suggest again a yearly charge the user dismissed', function () {
    forecastCharge($this->account, '2025-06-10', -32000, 'MAPFRE SEGURO HOGAR');
    forecastCharge($this->account, '2026-06-09', -32000, 'MAPFRE SEGURO HOGAR');
    ForecastDismissal::factory()->create(['user_id' => $this->user->id, 'fingerprint' => 'mapfre seguro hogar', 'kind' => ForecastSuggestionKind::Annual]);

    expect(annualCandidates($this->user))->toBe([]);
});
