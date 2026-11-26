<?php

use App\Enums\CategoryType;
use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastSuggestionKind;
use App\Models\Account;
use App\Models\Category;
use App\Models\ForecastDismissal;
use App\Models\ForecastItem;
use App\Models\Space;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;
use App\Services\Forecast\ForecastSuggestions;

const DETECTION_TODAY = '2026-11-26';

/**
 * @return list<ForecastCandidate>
 */
function recurringCandidates(User $user): array
{
    return app(ForecastSuggestions::class)->for($user, $user->current_space_id, DETECTION_TODAY)['recurring'];
}

function candidateNamed(User $user, string $fingerprint): ?ForecastCandidate
{
    return collect(recurringCandidates($user))->first(fn (ForecastCandidate $candidate): bool => $candidate->fingerprint === $fingerprint);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
});

it('finds a monthly bill whose references and dates change every month', function () {
    $utilities = forecastExpenseCategory($this->user);
    $charges = forecastMonthlyCharges(
        $this->account,
        '2026-01-03',
        11,
        [-6012, -6100, -6230, -6050, -6120, -6300, -6450, -6200, -6110, -6400, -6290],
        fn (int $index, string $date): string => sprintf('RECIBO IBERDROLA REF %d %s', 88412 + $index * 911, substr($date, 8, 2).'/'.substr($date, 5, 2)),
        $utilities,
    );

    $candidate = candidateNamed($this->user, 'recibo iberdrola');

    expect($candidate)->not->toBeNull()
        ->and($candidate->kind)->toBe(ForecastSuggestionKind::Recurring)
        ->and($candidate->frequency)->toBe(ForecastFrequency::Monthly)
        ->and($candidate->accountId)->toBe($this->account->id)
        ->and($candidate->amount)->toBe(-6267)
        ->and($candidate->amountTolerance)->toBe(183)
        ->and($candidate->anchorDay)->toBe(3)
        ->and($candidate->nextDate)->toBe('2026-12-03')
        ->and($candidate->lastChargedOn)->toBe('2026-11-03')
        ->and($candidate->confidence)->toBe(ForecastConfidence::High)
        ->and($candidate->categoryId)->toBe($utilities->id)
        ->and($candidate->currencyCode)->toBe('EUR')
        ->and($candidate->transactionIds)->toBe(array_map(fn ($charge) => $charge->id, $charges));
});

it('tells bimonthly and quarterly bills from monthly ones', function () {
    forecastMonthlyCharges($this->account, '2026-01-15', 6, -3800, 'CANAL ISABEL II AGUA', stepMonths: 2);
    forecastMonthlyCharges($this->account, '2025-11-20', 5, -12000, 'COMUNIDAD PROPIETARIOS', stepMonths: 3);

    expect(candidateNamed($this->user, 'canal isabel agua'))
        ->frequency->toBe(ForecastFrequency::Bimonthly)
        ->nextDate->toBe('2027-01-15')
        ->and(candidateNamed($this->user, 'comunidad propietarios'))
        ->frequency->toBe(ForecastFrequency::Quarterly)
        ->nextDate->toBe('2027-02-20');
});

it('ignores frequent shopping and subscriptions that stopped', function () {
    for ($date = Carbon\Carbon::parse('2026-06-01'); $date->lte('2026-11-24'); $date->addWeek()) {
        forecastCharge($this->account, $date->toDateString(), -random_int(2000, 9000), 'COMPRA TARJ MERCADONA');
    }
    forecastMonthlyCharges($this->account, '2026-01-12', 6, -1099, 'SPOTIFY P1A2B3');

    expect(recurringCandidates($this->user))->toBe([]);
});

it('needs at least three charges', function () {
    forecastMonthlyCharges($this->account, '2026-09-12', 2, -1099, 'DISNEY PLUS');

    expect(recurringCandidates($this->user))->toBe([]);
});

it('falls back to amount and day when a description says nothing stable', function () {
    forecastMonthlyCharges($this->account, '2026-04-01', 8, [-80000, -80000, -80000, -80000, -81000, -80000, -80000, -80000], fn (int $index): string => (string) (88412 + $index).' / 01-'.($index + 4));

    $candidates = recurringCandidates($this->user);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->fingerprint)->toBeNull()
        ->and($candidates[0]->confidence)->toBe(ForecastConfidence::Review)
        ->and($candidates[0]->frequency)->toBe(ForecastFrequency::Monthly)
        ->and($candidates[0]->amount)->toBe(-80000)
        ->and($candidates[0]->dismissalKey())->toBe('~'.$this->account->id.':out:1');
});

it('does not group charges by amount when their description names a payee', function () {
    forecastMonthlyCharges($this->account, '2026-03-10', 9, -4500, fn (int $index): string => 'COMPRA TIENDA '.chr(65 + $index).'ZUL');

    expect(recurringCandidates($this->user))->toBe([]);
});

it('reads a salary paid around the turn of the month as one schedule', function () {
    foreach (['2026-05-31', '2026-06-30', '2026-07-31', '2026-09-01', '2026-09-30', '2026-10-31'] as $date) {
        forecastCharge($this->account, $date, 250000, 'NOMINA ACME SL');
    }

    expect(candidateNamed($this->user, 'nomina acme'))
        ->kind->toBe(ForecastSuggestionKind::Recurring)
        ->amount->toBe(250000)
        ->anchorDay->toBe(31)
        ->nextDate->toBe('2026-11-30');
});

it('continues after the occurrence an early charge paid', function () {
    foreach (['2026-06-01', '2026-07-01', '2026-08-01', '2026-08-31', '2026-10-01', '2026-10-30'] as $date) {
        forecastCharge($this->account, $date, 180000, 'NOMINA GLOBEX');
    }

    expect(candidateNamed($this->user, 'nomina globex'))
        ->anchorDay->toBe(1)
        ->nextDate->toBe('2026-12-01');
});

it('proposes a transfer between two accounts once, from the account it leaves', function () {
    $savings = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    forecastMonthlyCharges($this->account, '2026-05-05', 7, -50000, 'TRASPASO A CUENTA AHORRO');
    forecastMonthlyCharges($savings, '2026-05-06', 7, 50000, 'TRASPASO RECIBIDO');

    $candidates = recurringCandidates($this->user);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->kind)->toBe(ForecastSuggestionKind::Transfer)
        ->and($candidates[0]->accountId)->toBe($this->account->id)
        ->and($candidates[0]->destinationAccountId)->toBe($savings->id)
        ->and($candidates[0]->destinationUnknown)->toBeFalse();
});

it('flags a transfer whose other side is not in the app', function () {
    $investment = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Investment]);
    forecastMonthlyCharges($this->account, '2026-05-02', 7, -20000, 'INDEXA CAPITAL APORTACION', $investment);

    expect(candidateNamed($this->user, 'indexa capital aportacion'))
        ->kind->toBe(ForecastSuggestionKind::Transfer)
        ->destinationAccountId->toBeNull()
        ->destinationUnknown->toBeTrue();
});

it('does not propose again what the user dismissed or already plans', function () {
    forecastMonthlyCharges($this->account, '2026-03-08', 9, -1299, 'NETFLIX.COM');
    forecastMonthlyCharges($this->account, '2026-03-14', 9, -4999, 'GIMNASIO FIT');
    expect(recurringCandidates($this->user))->toHaveCount(2);

    ForecastDismissal::factory()->create(['user_id' => $this->user->id, 'fingerprint' => 'netflix com', 'kind' => ForecastSuggestionKind::Recurring]);
    ForecastItem::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->account->id, 'fingerprint' => 'gimnasio fit']);

    expect(recurringCandidates($this->user))->toBe([]);
});

it('reads only the user\'s own transactions in the space', function () {
    $partner = User::factory()->create();
    $partnerAccount = Account::factory()->create(['user_id' => $partner->id, 'space_id' => $this->user->current_space_id, 'currency_code' => 'EUR']);
    forecastMonthlyCharges($partnerAccount, '2026-03-08', 9, -1299, 'NETFLIX.COM');

    $otherSpace = Space::factory()->create();
    $elsewhere = Account::factory()->create(['user_id' => $this->user->id, 'space_id' => $otherSpace->id, 'currency_code' => 'EUR']);
    forecastMonthlyCharges($elsewhere, '2026-03-08', 9, -1299, 'HBO MAX');

    expect(recurringCandidates($this->user))->toBe([]);
});
