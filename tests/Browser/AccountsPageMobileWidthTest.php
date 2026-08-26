<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\RealEstateDetail;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('does not scroll horizontally on a small phone', function () {
    $user = User::factory()->onboarded()->create();

    // Real estate accounts carry the longest action label ("Update market
    // value"), so their card is the first one to outgrow a phone viewport.
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'bank_id' => null,
        'name' => 'Beach Condo',
        'type' => AccountType::RealEstate,
        'currency_code' => 'EUR',
    ]);

    RealEstateDetail::factory()->create([
        'account_id' => $account->id,
        'linked_loan_account_id' => null,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2024-01-01',
        'balance' => 30000000,
    ]);

    actingAs($user);

    // 320px wide: the narrowest phone the app has to fit, and the width where
    // every translation has to fit rather than get lucky.
    $page = visit('/accounts')
        ->on()
        ->iPhoneSE()
        ->navigate('/accounts', ['waitUntil' => 'domcontentloaded']);

    // accountMetrics is a deferred prop, so the cards render as skeletons
    // first, and that state has its own width to get wrong. Waiting and
    // measuring in one evaluation keeps the metrics from landing in between.
    // The overflow shows up on the page's scroll container, not the document.
    $skeletonOverflow = (int) $page->script(<<<'JS'
        (async () => {
            const deadline = Date.now() + 3000;
            while (!document.querySelector('.animate-pulse') && Date.now() < deadline) {
                await new Promise((resolve) => setTimeout(resolve, 10));
            }

            const main = document.querySelector('[data-slot="sidebar-inset"]');

            return main.scrollWidth - main.clientWidth;
        })()
    JS);

    expect($skeletonOverflow)->toBeLessThanOrEqual(0);

    $page->waitForText('Update market value');

    $loadedOverflow = (int) $page->script(<<<'JS'
        (() => {
            const main = document.querySelector('[data-slot="sidebar-inset"]');

            return main.scrollWidth - main.clientWidth;
        })()
    JS);

    expect($loadedOverflow)->toBeLessThanOrEqual(0);

    $page->assertNoJavascriptErrors();
});
