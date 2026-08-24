<?php

use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\WiseClient;
use App\Services\Banking\WiseTransactionSyncService;
use Illuminate\Support\Facades\Http;

test('the walk pages through the whole history instead of re-reading page one', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $connection = BankingConnection::factory()->wise()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => '35242290:EUR',
        'currency_code' => 'EUR',
    ]);

    // Wise hands the cursor back as `cursor` but only reads it as `nextCursor`,
    // so a fake that keys off `nextCursor` is what tells the two apart: sending
    // the wrong name looks exactly like a one-page history that never ends.
    Http::fake([
        'api.wise.com/v1/profiles/*/activities*' => function ($request) {
            $cursor = $request->data()['nextCursor'] ?? null;

            return Http::response(match ($cursor) {
                null => [
                    'activities' => [activityFixture(1)],
                    'cursor' => 'page-2',
                ],
                'page-2' => [
                    'activities' => [activityFixture(2)],
                    'cursor' => null,
                ],
                default => ['activities' => [], 'cursor' => null],
            });
        },
    ]);

    $created = app(WiseTransactionSyncService::class)->sync(
        $account,
        new WiseClient('test-token'),
        now()->subYear()->toDateString(),
        now()->toDateString(),
    );

    expect($created)->toBe(2);
    Http::assertSentCount(2);
    expect($account->transactions()->pluck('description')->sort()->values()->all())
        ->toBe(['Purchase 1', 'Purchase 2']);
});

function activityFixture(int $n): array
{
    return [
        'id' => "activity-{$n}",
        'type' => 'CARD_PAYMENT',
        'title' => "Purchase {$n}",
        'primaryAmount' => '<negative>10.00 EUR</negative>',
        'secondaryAmount' => '',
        'createdOn' => now()->subDays($n)->toIso8601String(),
    ];
}
