<?php

use App\Enums\AccountType;
use App\Enums\ForecastConfidence;
use App\Enums\ForecastExclusionReason;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastSpikeAnswer;
use App\Enums\ForecastSuggestionKind;
use App\Models\Account;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastItem;
use App\Models\User;
use App\Services\Forecast\ForecastSpikeAnswers;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create(['type' => AccountType::Checking, 'user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->category = forecastExpenseCategory($this->user);
    $this->spike = forecastCharge($this->account, '2026-11-20', -120000, 'SEGURO HOGAR MAPFRE 2026', $this->category);
});

it('leaves a one-off out of the estimate, once', function () {
    $answers = app(ForecastSpikeAnswers::class);

    expect($answers->answer($this->spike, ForecastSpikeAnswer::OneOff))->toBeNull()
        ->and($answers->answer($this->spike, ForecastSpikeAnswer::OneOff))->toBeNull();

    $exclusions = ForecastExcludedTransaction::query()->get();

    expect($exclusions)->toHaveCount(1)
        ->and($exclusions->first()->transaction_id)->toBe($this->spike->id)
        ->and($exclusions->first()->reason)->toBe(ForecastExclusionReason::OneOff);
});

it('returns the yearly line a repeating charge would become, creating nothing', function () {
    $candidate = app(ForecastSpikeAnswers::class)->answer($this->spike, ForecastSpikeAnswer::Repeats);

    expect($candidate->kind)->toBe(ForecastSuggestionKind::Annual)
        ->and($candidate->frequency)->toBe(ForecastFrequency::Yearly)
        ->and($candidate->confidence)->toBe(ForecastConfidence::Review)
        ->and($candidate->accountId)->toBe($this->account->id)
        ->and($candidate->categoryId)->toBe($this->category->id)
        ->and($candidate->amount)->toBe(-120000)
        ->and($candidate->nextDate)->toBe('2027-11-20')
        ->and($candidate->transactionIds)->toBe([$this->spike->id])
        ->and($candidate->fingerprint)->not->toBeEmpty()
        ->and(ForecastItem::query()->count())->toBe(0)
        ->and(ForecastExcludedTransaction::query()->count())->toBe(0);
});

it('puts a charge back into the estimate when the answer changes to repeats', function () {
    $answers = app(ForecastSpikeAnswers::class);
    $answers->answer($this->spike, ForecastSpikeAnswer::OneOff);

    $answers->answer($this->spike, ForecastSpikeAnswer::Repeats);

    expect(ForecastExcludedTransaction::query()->count())->toBe(0);
});
