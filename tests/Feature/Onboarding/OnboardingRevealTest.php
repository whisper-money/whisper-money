<?php

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Models\User;

/** Somebody mid-onboarding, whose currency the figures come back in. */
function revealUser(): User
{
    return User::factory()->create([
        'onboarded_at' => null,
        'currency_code' => 'EUR',
    ]);
}

/** A movement on a given month, named and priced. */
function outgoing(Account $account, string $month, int $amount, ?string $merchant = null, int $day = 5): Transaction
{
    return Transaction::factory()->for($account->user)->for($account)->plaintext()->create([
        'category_id' => null,
        'transaction_date' => $month.'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
        'amount' => $amount,
        'currency_code' => 'EUR',
        'creditor_name' => $merchant,
    ]);
}

/** The month a reveal written today is about, and the two before it. */
function monthsBack(int $back): string
{
    return now()->startOfMonth()->subMonths($back)->format('Y-m');
}

it('answers the guess with what last month actually cost', function () {
    $user = revealUser();
    $account = Account::factory()->for($user)->create(['type' => 'checking', 'currency_code' => 'EUR']);

    outgoing($account, monthsBack(1), -31200, 'MERCADONA');
    outgoing($account, monthsBack(1), -14800, 'GLOVO');
    outgoing($account, monthsBack(1), -12100, 'AMAZON');
    outgoing($account, monthsBack(1), -5000, 'AMAZON');
    outgoing($account, monthsBack(1), -2000, 'ZARA');
    // Money coming in is not money going out.
    outgoing($account, monthsBack(1), 200000, 'PAYROLL');

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson([
            'variant' => 'spending',
            'currency_code' => 'EUR',
            'month' => monthsBack(1),
            'is_last_month' => true,
            'spent' => 65100,
            'merchants' => [
                ['name' => 'MERCADONA', 'amount' => 31200],
                ['name' => 'AMAZON', 'amount' => 17100],
                ['name' => 'GLOVO', 'amount' => 14800],
            ],
        ]);
});

it('falls back to the last month that holds enough movements', function () {
    $user = revealUser();
    $account = Account::factory()->for($user)->create(['type' => 'checking', 'currency_code' => 'EUR']);

    // A file exported months ago is still a real import, and a month with one
    // stray movement in it is not the month to answer the guess with.
    outgoing($account, monthsBack(1), -1000, 'KIOSK');

    foreach (range(1, 5) as $day) {
        outgoing($account, monthsBack(4), -1000, 'MERCADONA', $day);
    }

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson([
            'variant' => 'spending',
            'month' => monthsBack(4),
            'is_last_month' => false,
            'spent' => 5000,
        ]);
});

it('leaves balance-tracking accounts out of the spending figure', function () {
    $user = revealUser();
    $checking = Account::factory()->for($user)->create(['type' => 'checking', 'currency_code' => 'EUR']);
    $pension = Account::factory()->for($user)->create(['type' => 'retirement', 'currency_code' => 'EUR']);

    foreach (range(1, 5) as $day) {
        outgoing($checking, monthsBack(1), -1000, 'MERCADONA', $day);
    }

    // A pension's rows are adjustments, not purchases.
    outgoing($pension, monthsBack(1), -500000, 'PENSION ADJUSTMENT');

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson(['spent' => 5000]);
});

it('counts the merchants that charged the same amount three months running', function () {
    $user = revealUser();
    $account = Account::factory()->for($user)->create(['type' => 'checking', 'currency_code' => 'EUR']);

    foreach ([1, 2, 3] as $back) {
        $month = monthsBack($back);

        outgoing($account, $month, -1299, 'NETFLIX');
        outgoing($account, $month, -999, 'SPOTIFY');
        // Same merchant, a different amount every month: not a subscription.
        outgoing($account, $month, -1000 * $back, 'MERCADONA');
        outgoing($account, $month, -2000, 'GLOVO', 6);
        outgoing($account, $month, -3000, 'AMAZON', 7);
    }

    // Three months running means three, so a two-month run does not count.
    outgoing($account, monthsBack(1), -4900, 'GYM');
    outgoing($account, monthsBack(2), -4900, 'GYM');

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson([
            'month' => monthsBack(1),
            'recurring_count' => 4,
        ]);
});

it('shows what someone with no movements is worth instead', function () {
    $user = revealUser();

    $pension = Account::factory()->for($user)->create([
        'name' => 'Plan de pensiones',
        'type' => 'retirement',
        'currency_code' => 'EUR',
    ]);
    $mortgage = Account::factory()->for($user)->create([
        'name' => 'Hipoteca',
        'type' => 'loan',
        'currency_code' => 'EUR',
    ]);

    AccountBalance::factory()->for($pension)->create([
        'balance_date' => now()->subDay(),
        'balance' => 1840000,
    ]);
    AccountBalance::factory()->for($mortgage)->create([
        'balance_date' => now()->subDay(),
        'balance' => 205000,
    ]);

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson([
            'variant' => 'assets',
            'net_worth' => 1635000,
            'accounts' => [
                ['name' => 'Plan de pensiones', 'connected' => false, 'balance' => 1840000],
                ['name' => 'Hipoteca', 'connected' => false, 'balance' => -205000],
            ],
        ]);
});

it('names the merchant from the description when the row has no counterparty', function () {
    $user = revealUser();
    $account = Account::factory()->for($user)->create(['type' => 'checking', 'currency_code' => 'EUR']);

    // What a file import leaves behind: no counterparty, only what the bank
    // wrote on the line.
    foreach (range(1, 5) as $day) {
        outgoing($account, monthsBack(1), -1000, null, $day)
            ->update(['description' => 'PAGO TARJETA CARREFOUR']);
    }

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson([
            'merchants' => [['name' => 'PAGO TARJETA CARREFOUR', 'amount' => 5000]],
            'merchant_count' => 1,
        ]);
});

it('is closed to guests', function () {
    $this->getJson('/onboarding/reveal')->assertUnauthorized();
});

it('reveals the last finished month rather than the one still running', function () {
    $user = revealUser();
    $account = Account::factory()->for($user)->create(['type' => 'checking', 'currency_code' => 'EUR']);

    // Anyone on a live bank connection has movements in the month still
    // running. A handful of them is not a month, and setting them against a
    // guess about a whole one is the comparison nobody made.
    foreach (range(1, 6) as $day) {
        outgoing($account, now()->format('Y-m'), -1350, 'KIOSK', $day);
    }

    foreach (range(1, 11) as $back) {
        foreach (range(1, 5) as $day) {
            outgoing($account, monthsBack($back), -41800, 'MERCADONA', $day);
        }
    }

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson([
            'variant' => 'spending',
            'month' => monthsBack(1),
            'is_last_month' => true,
            'is_partial' => false,
            'spent' => 209000,
        ]);
});

it('answers a user who only has the running month, without comparing it', function () {
    $user = revealUser();
    $account = Account::factory()->for($user)->create(['type' => 'checking', 'currency_code' => 'EUR']);

    foreach (range(1, 6) as $day) {
        outgoing($account, now()->format('Y-m'), -1350, 'KIOSK', $day);
    }

    $this->actingAs($user)
        ->getJson('/onboarding/reveal')
        ->assertOk()
        ->assertJson([
            'variant' => 'spending',
            'month' => now()->format('Y-m'),
            'is_partial' => true,
            'spent' => 8100,
        ]);
});
