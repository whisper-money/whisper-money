<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\Sync\WiseSyncer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * A wallet with more history than one run can walk. The page count is capped so a
 * broken budget fails on the request count instead of hanging CI, and each page
 * advances the clock to stand in for the seconds a real request spends.
 */
function fakeLongWiseHistory(int $secondsPerPage = 20, int $pages = 40): void
{
    Carbon::setTestNow(Carbon::now());

    Http::fake([
        'api.wise.com/v2/borderless-accounts*' => Http::response([
            [
                'id' => 44333087,
                'profileId' => 35242290,
                'balances' => [
                    ['currency' => 'EUR', 'amount' => ['value' => 19.81, 'currency' => 'EUR']],
                ],
            ],
        ]),
        'api.wise.com/v1/profiles/*/activities*' => function ($request) use ($secondsPerPage, $pages) {
            $page = (int) str_replace('page-', '', $request->data()['nextCursor'] ?? 'page-1');
            Carbon::setTestNow(Carbon::now()->addSeconds($secondsPerPage));

            return Http::response([
                'activities' => [[
                    'id' => "activity-{$page}",
                    'type' => 'CARD_PAYMENT',
                    'title' => "Purchase {$page}",
                    'primaryAmount' => '<negative>10.00 EUR</negative>',
                    'secondaryAmount' => '',
                    'createdOn' => now()->subDays($page)->toIso8601String(),
                ]],
                'cursor' => $page < $pages ? 'page-'.($page + 1) : null,
            ]);
        },
    ]);
}

function wiseWalletsFor(User $user, BankingConnection $connection, string ...$currencies): void
{
    foreach ($currencies as $currency) {
        Account::factory()->connected()->create([
            'user_id' => $user->id,
            'banking_connection_id' => $connection->id,
            'external_account_id' => '35242290:'.$currency,
            'currency_code' => $currency,
        ]);
    }
}

afterEach(function () {
    Carbon::setTestNow();
});

test('a history longer than the budget stops short instead of getting the job killed', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->wise()->create(['user_id' => $user->id]);
    wiseWalletsFor($user, $connection, 'EUR');

    fakeLongWiseHistory();

    $metadata = app(WiseSyncer::class)->sync($connection, isFirstSync: true);

    // 20s of clock per page against a 90s budget: the fifth page closes it, so the
    // walk stops well before the 40 pages on offer.
    expect($metadata['transactions_synced'])->toBe(5);
    expect($metadata['wallets_skipped_on_budget'])->toBe(0);
});

test('the budget covers the whole connection, not each wallet separately', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->wise()->create(['user_id' => $user->id]);
    // Wise creates one account per currency per profile, so multi-wallet is the
    // normal case. A budget each would multiply past the job's 120s timeout and
    // reproduce the original bug.
    wiseWalletsFor($user, $connection, 'EUR', 'USD', 'GBP');

    fakeLongWiseHistory();

    $metadata = app(WiseSyncer::class)->sync($connection, isFirstSync: true);

    expect($metadata['wallets_skipped_on_budget'])->toBeGreaterThan(0);
    // The whole run stays within one budget's worth of pages rather than one per
    // wallet, which is what keeps it inside the job's timeout.
    expect(count(Http::recorded()))->toBeLessThan(12);
});

test('a wallet whose balance request fails still gets its transactions', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->wise()->create(['user_id' => $user->id]);
    wiseWalletsFor($user, $connection, 'EUR');

    Http::fake([
        'api.wise.com/v2/borderless-accounts*' => Http::response(['message' => 'boom'], 500),
        'api.wise.com/v1/profiles/*/activities*' => Http::response([
            'activities' => [[
                'id' => 'activity-1',
                'type' => 'CARD_PAYMENT',
                'title' => 'Purchase 1',
                'primaryAmount' => '<negative>10.00 EUR</negative>',
                'secondaryAmount' => '',
                'createdOn' => now()->subDay()->toIso8601String(),
            ]],
            'cursor' => null,
        ]),
    ]);

    $metadata = app(WiseSyncer::class)->sync($connection, isFirstSync: true);

    // Ordering the balance first must not make it load-bearing: before this, a
    // balance hiccup would have cost the user their transactions too.
    expect($metadata['balances_failed'])->toBe(1);
    expect($metadata['transactions_synced'])->toBe(1);
});
