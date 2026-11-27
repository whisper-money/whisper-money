<?php

use App\Enums\AccountType;
use App\Features\Forecast;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;
use App\Services\Forecast\ForecastAccounts;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

/**
 * The forecast lists the accounts in the user's own order (position, then
 * name), as the accounts page and the dashboard do, never alphabetically.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-26 12:00:00');
    config(['subscriptions.enabled' => true]);

    $this->user = User::factory()->onboarded()->paywallSeen()->subscribed()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);
    $this->spaceId = $this->user->activeSpace()->id;

    $account = function (string $name, int $position, AccountType $type = AccountType::Checking): Account {
        $account = Account::factory()->create([
            'user_id' => $this->user->id,
            'name' => $name,
            'type' => $type,
            'currency_code' => 'EUR',
            'position' => $position,
        ]);
        AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => '2026-11-20', 'balance' => 100000]);

        return $account;
    };

    $this->zeta = $account('Zeta', 0);
    $this->brokerage = $account('Brokerage', 1, AccountType::Investment);
    $this->alpha = $account('Alpha', 2);
    $this->beta = $account('Beta', 2);
    $this->expected = [$this->zeta->id, $this->brokerage->id, $this->alpha->id, $this->beta->id];
});

it('includes and offers the accounts by position, then by name', function () {
    $accounts = app(ForecastAccounts::class);

    expect($accounts->included($this->user, $this->spaceId)->modelKeys())->toBe($this->expected)
        ->and(array_column($accounts->choices($this->user, $this->spaceId), 'id'))->toBe($this->expected);
});

it('sends the account filter in the user order, on one account and on all of them', function (string $account) {
    $forecast = $this->actingAs($this->user)
        ->get(route('forecast.index', ['account' => $account === 'one' ? $this->alpha->id : 'all']))
        ->assertOk()
        ->viewData('page')['props']['forecast'];

    expect(array_column($forecast['accounts'], 'id'))->toBe($this->expected);
})->with(['one', 'all']);
