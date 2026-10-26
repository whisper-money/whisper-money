<?php

use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;

/**
 * The export as rows of cells, header included, without the byte order mark.
 *
 * @param  array<string, mixed>  $payload
 * @return list<list<string>>
 */
function exportedRows(User $user, array $payload = []): array
{
    $response = test()->actingAs($user)->post(route('transactions.export'), $payload)->assertOk();

    $lines = explode("\n", trim(ltrim($response->streamedContent(), "\u{FEFF}")));

    return array_map(fn (string $line): array => str_getcsv($line, escape: ''), $lines);
}

test('a user downloads their transactions as a csv file', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $bank = Bank::factory()->create(['name' => 'Acme Bank']);
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Checking', 'bank_id' => $bank->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Corner shop, "the good one"',
        'transaction_date' => '2026-03-14',
        'amount' => -1250,
        'currency_code' => 'EUR',
        'notes' => 'Weekly shop',
        'creditor_name' => 'Corner Shop SL',
        'debtor_name' => null,
    ]);
    $transaction->labels()->attach([
        Label::factory()->create(['user_id' => $user->id, 'name' => 'Home'])->id,
        Label::factory()->create(['user_id' => $user->id, 'name' => 'Shared'])->id,
    ]);

    $response = $this->actingAs($user)->post(route('transactions.export'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('transactions-'.today()->format('Y-m-d').'.csv');

    expect($response->streamedContent())->toStartWith("\u{FEFF}");

    [$header, $row] = exportedRows($user);

    expect($header)->toBe(['Date', 'Description', 'Amount', 'Currency', 'Account', 'Bank', 'Category', 'Labels', 'Notes', 'Creditor', 'Debtor'])
        ->and($row)->toBe(['2026-03-14', 'Corner shop, "the good one"', '-12.50', 'EUR', 'Checking', 'Acme Bank', 'Groceries', 'Home, Shared', 'Weekly shop', 'Corner Shop SL', '']);
});

test('the export follows the sort of the transactions page', function (array $transactions, array $payload, array $expectedDescriptions) {
    $user = User::factory()->create(['onboarded_at' => now()]);

    foreach ($transactions as $attributes) {
        Transaction::factory()->create(['user_id' => $user->id, ...$attributes]);
    }

    expect(array_column(exportedRows($user, $payload), 1))->toBe(['Description', ...$expectedDescriptions]);
})->with([
    'newest first by default' => [
        [['description' => 'Older', 'transaction_date' => '2026-01-01'], ['description' => 'Newer', 'transaction_date' => '2026-02-01']],
        [],
        ['Newer', 'Older'],
    ],
    'amount ascending' => [
        [['description' => 'Big', 'amount' => -9000], ['description' => 'Small', 'amount' => -100]],
        ['sort' => 'amount'],
        ['Big', 'Small'],
    ],
    'amount descending' => [
        [['description' => 'Big', 'amount' => -9000], ['description' => 'Small', 'amount' => -100]],
        ['sort' => '-amount'],
        ['Small', 'Big'],
    ],
    'payee with blanks first' => [
        [['description' => 'Named', 'creditor_name' => 'Acme'], ['description' => 'Blank', 'creditor_name' => null]],
        ['sort' => 'creditor_name'],
        ['Blank', 'Named'],
    ],
]);

test('the export never includes another user\'s transactions', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->create(['user_id' => $user->id, 'description' => 'Mine']);
    $theirs = Transaction::factory()->create(['description' => 'Theirs']);

    expect(array_column(exportedRows($user), 1))->toBe(['Description', 'Mine'])
        ->and(array_column(exportedRows($user, ['ids' => [$theirs->id]]), 1))->toBe(['Description']);
});

test('the export honours the filters and search of the transactions page', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $groceries = Category::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'category_id' => $groceries->id, 'description' => 'Market in range', 'transaction_date' => '2026-03-10']);
    Transaction::factory()->create(['user_id' => $user->id, 'category_id' => $groceries->id, 'description' => 'Bakery in range', 'transaction_date' => '2026-03-12']);
    Transaction::factory()->create(['user_id' => $user->id, 'category_id' => $groceries->id, 'description' => 'Market too old', 'transaction_date' => '2026-01-10']);
    Transaction::factory()->create(['user_id' => $user->id, 'description' => 'Market other category', 'transaction_date' => '2026-03-11']);

    $rows = exportedRows($user, [
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
        'category_ids' => $groceries->id,
        'search' => 'Market',
    ]);

    expect(array_column($rows, 1))->toBe(['Description', 'Market in range']);
});

test('only the loaded transactions are exported when their ids are given', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $loaded = Transaction::factory()->create(['user_id' => $user->id, 'description' => 'Loaded', 'transaction_date' => '2026-03-02']);
    $alsoLoaded = Transaction::factory()->create(['user_id' => $user->id, 'description' => 'Also loaded', 'transaction_date' => '2026-03-01']);
    Transaction::factory()->create(['user_id' => $user->id, 'description' => 'Not loaded yet', 'transaction_date' => '2026-02-01']);

    expect(array_column(exportedRows($user, ['ids' => [$alsoLoaded->id, $loaded->id]]), 1))
        ->toBe(['Description', 'Loaded', 'Also loaded']);
});

test('amounts are written at the scale of their own currency', function (string $currency, int $minorUnits, string $expected) {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->create(['user_id' => $user->id, 'amount' => $minorUnits, 'currency_code' => $currency]);

    expect(exportedRows($user)[1][2])->toBe($expected);
})->with([
    'two decimals' => ['USD', 123456, '1234.56'],
    'no minor unit' => ['JPY', 1500, '1500'],
    'negative' => ['EUR', -5, '-0.05'],
]);

test('text a spreadsheet would run as a formula is neutralised', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'description' => '=HYPERLINK("http://evil.test","Click")',
        'notes' => '+1 for this',
    ]);

    $row = exportedRows($user)[1];

    expect($row[1])->toBe('\'=HYPERLINK("http://evil.test","Click")')
        ->and($row[8])->toBe("'+1 for this");
});

test('an export with no transactions still has its header', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    expect(exportedRows($user))->toHaveCount(1);
});

test('an export rejects ids that are not uuids', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $this->actingAs($user)
        ->postJson(route('transactions.export'), ['ids' => ['not-a-uuid']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids.0');
});

test('guests cannot export transactions', function () {
    $this->post(route('transactions.export'))->assertRedirect();
});

test('the count matches the transactions the filters select', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->count(3)->create(['user_id' => $user->id, 'description' => 'Coffee', 'transaction_date' => '2026-03-10']);
    Transaction::factory()->create(['user_id' => $user->id, 'description' => 'Coffee', 'transaction_date' => '2026-01-10']);
    Transaction::factory()->create(['user_id' => $user->id, 'description' => 'Rent', 'transaction_date' => '2026-03-10']);
    Transaction::factory()->count(2)->create(['description' => 'Coffee', 'transaction_date' => '2026-03-10']);

    $this->actingAs($user)
        ->getJson(route('api.transactions.count', ['search' => 'Coffee', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
        ->assertOk()
        ->assertExactJson(['count' => 3]);

    $this->actingAs($user)
        ->getJson(route('api.transactions.count'))
        ->assertOk()
        ->assertExactJson(['count' => 5]);
});

test('guests cannot count transactions', function () {
    $this->getJson(route('api.transactions.count'))->assertUnauthorized();
});

test('the list sort refuses a column it does not know', function () {
    Transaction::query()->sortedForList('-id; drop table users');
})->throws(InvalidArgumentException::class);

test('the export keeps its own rate limit, apart from the api routes', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    foreach (range(1, 12) as $ignored) {
        $this->actingAs($user)->getJson(route('api.transactions.count'))->assertOk();
    }

    $this->actingAs($user)->post(route('transactions.export'))->assertOk();

    foreach (range(1, 9) as $ignored) {
        $this->actingAs($user)->post(route('transactions.export'))->assertOk();
    }

    $this->actingAs($user)->post(route('transactions.export'))->assertTooManyRequests();
});
