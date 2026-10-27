<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    // The factory books rows in random currencies, which the day totals convert.
    fakeCurrencyApi();
});

test('index returns paginated transactions as Inertia props', function () {
    Transaction::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('transactions.data', 3)
        ->has('appliedFilters')
        ->missing('appliedFilters.sort')
    );
});

test('transactions include eager-loaded relationships', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $transaction = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);
    $transaction->labels()->attach($label->id);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data.0.account')
        ->has('transactions.data.0.category')
        ->has('transactions.data.0.labels', 1)
    );
});

test('filter by date range', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-01-01',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'date_from' => '2025-06-01',
        'date_to' => '2025-06-30',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('date range includes its first and last day', function () {
    foreach (['2025-05-31', '2025-06-01', '2025-06-30', '2025-07-01'] as $date) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'transaction_date' => $date,
        ]);
    }

    actingAs($this->user)->get(route('transactions.index', [
        'date_from' => '2025-06-01',
        'date_to' => '2025-06-30',
    ]))->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.transaction_date', '2025-06-30')
        ->where('transactions.data.1.transaction_date', '2025-06-01')
    );
});

test('date range scope keeps the whole boundary day when a bound carries a time', function () {
    foreach (['2025-05-31', '2025-06-01', '2025-06-30', '2025-07-01'] as $date) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'transaction_date' => $date,
        ]);
    }

    // Saved filters and queued re-evaluations carry whatever was stored when
    // they were written, so the scope cannot rely on the request validator.
    $dates = Transaction::query()
        ->applyFilters(['date_from' => '2025-06-01 18:30:00', 'date_to' => '2025-06-30T09:15:00'])
        ->orderBy('transaction_date')
        ->pluck('transaction_date')
        ->map->toDateString()
        ->all();

    expect($dates)->toBe(['2025-06-01', '2025-06-30']);
});

test('date range scope matches nothing for an unparseable bound instead of throwing or dropping it', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    expect(Transaction::query()->applyFilters(['date_from' => 'not-a-date'])->count())->toBe(0);
});

test('rejects a date filter that is not a plain Y-m-d date', function (string $date) {
    // `date` accepts every one of these - it only asks strtotime() to parse the
    // string and checkdate() to like the pieces, so "275752-07-04" is a date in
    // year 275752 and "01/06/2025" is whichever of June and January PHP feels
    // like. The raw string then reached both the SQL and the Inertia props, where
    // the client handed it to date-fns `format()` and the page died mid-render.
    // The UI only ever sends the output of a `date` input, so nothing legitimate
    // is turned away.
    actingAs($this->user)
        ->get(route('transactions.index', ['date_from' => $date]))
        ->assertSessionHasErrors('date_from');
})->with([
    '275752-07-04',
    '01/06/2025',
    '15-06-2026',
    '2026-6-5',
    '2025-06-15T00:00:00Z',
]);

test('filter by amount range', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -5000, // -$50.00
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -20000, // -$200.00
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'amount_min' => -100,
        'amount_max' => -10,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('filter by category', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => $category->id,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('filtering by a parent category includes transactions from its descendants', function () {
    $parent = Category::factory()->create(['user_id' => $this->user->id]);
    $child = Category::factory()->childOf($parent)->create(['user_id' => $this->user->id]);
    $grandchild = Category::factory()->childOf($child)->create(['user_id' => $this->user->id]);
    $unrelated = Category::factory()->create(['user_id' => $this->user->id]);

    foreach ([$parent, $child, $grandchild, $unrelated] as $category) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $category->id,
        ]);
    }

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => $parent->id,
    ]));

    // Parent + child + grandchild, but not the unrelated category.
    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 3)
    );
});

test('filter by uncategorized', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => 'uncategorized',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.category_id', null)
    );
});

test('filter by account', function () {
    $otherAccount = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $otherAccount->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'account_ids' => $this->account->id,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('filter by label', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $txWithLabel = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    $txWithLabel->labels()->attach($label->id);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'label_ids' => $label->id,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('category and label filters combine with OR', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $txWithCategory = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    $txWithLabel = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);
    $txWithLabel->labels()->attach($label->id);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => $category->id,
        'label_ids' => $label->id,
    ]));

    $ids = collect($response->viewData('page')['props']['transactions']['data'])->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($txWithCategory->id)
        ->and($ids)->toContain($txWithLabel->id);
});

test('search matches', function (array $matching, array $other, string $search) {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        ...$matching,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        ...$other,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => $search,
    ]));

    $response->assertInertia(function ($page) use ($matching) {
        $page->has('transactions.data', 1);

        foreach ($matching as $attribute => $value) {
            $page->where("transactions.data.0.{$attribute}", $value);
        }
    });
})->with([
    'description' => [
        ['description' => 'Grocery Store Purchase'],
        ['description' => 'Gas Station'],
        'Grocery',
    ],
    'counterparty names' => [
        ['description' => 'Card payment', 'creditor_name' => 'Amazon EU'],
        ['description' => 'Card payment', 'creditor_name' => 'Coffee Shop'],
        'Amazon',
    ],
    'notes' => [
        ['description' => 'Some purchase', 'notes' => 'weekly groceries for the family'],
        ['description' => 'Another purchase', 'notes' => null],
        'groceries',
    ],
]);

test('filter by counterparty name', function (string $field, string $matching, string $other, string $filter) {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        $field => $matching,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        $field => $other,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        $field => $filter,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where("transactions.data.0.{$field}", $matching)
        ->where("appliedFilters.{$field}", $filter)
    );
})->with([
    'creditor' => ['creditor_name', 'Amazon EU', 'Coffee Shop', 'Amazon'],
    'debtor' => ['debtor_name', 'Payroll GmbH', 'Other Sender', 'Payroll'],
]);

test('combined filters work', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Matches all filters
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
        'transaction_date' => '2025-06-15',
        'amount' => -5000,
        'description' => 'Target purchase',
    ]);

    // Wrong date
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
        'transaction_date' => '2025-01-01',
        'amount' => -5000,
        'description' => 'Target purchase',
    ]);

    // Wrong category
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'transaction_date' => '2025-06-15',
        'amount' => -5000,
        'description' => 'Target purchase',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'date_from' => '2025-06-01',
        'date_to' => '2025-06-30',
        'category_ids' => $category->id,
        'search' => 'Target',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('lists transactions newest first, whatever sort the URL asks for', function (array $first, array $second, array $query, string $attribute, string|int $expectedFirst) {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        ...$first,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        ...$second,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', $query));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where("transactions.data.0.{$attribute}", $expectedFirst)
    );
})->with([
    'without a sort' => [['transaction_date' => '2025-06-15'], ['transaction_date' => '2025-01-01'], [], 'transaction_date', '2025-06-15'],
    'a bookmarked date ascending' => [['transaction_date' => '2025-01-01'], ['transaction_date' => '2025-06-15'], ['sort' => 'transaction_date'], 'transaction_date', '2025-06-15'],
    'a bookmarked amount' => [['transaction_date' => '2025-06-15', 'amount' => 5000], ['transaction_date' => '2025-01-01', 'amount' => -10000], ['sort' => 'amount'], 'amount', 5000],
    'a bookmarked payee' => [['transaction_date' => '2025-06-15', 'creditor_name' => 'Zed'], ['transaction_date' => '2025-01-01', 'creditor_name' => 'Amy'], ['sort' => 'creditor_name'], 'creditor_name', 'Zed'],
    'a sort it never knew' => [['transaction_date' => '2025-06-15'], ['transaction_date' => '2025-01-01'], ['sort' => 'bogus'], 'transaction_date', '2025-06-15'],
]);

test('cursor pagination returns correct results', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->count(25)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'per_page' => 10,
    ]));

    $nextCursor = null;

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 10)
        ->where('transactions.next_cursor', function ($cursor) use (&$nextCursor) {
            $nextCursor = $cursor;

            return $cursor !== null;
        })
    );

    // Fetch the next page using the cursor
    $response2 = actingAs($this->user)->get(route('transactions.index', [
        'per_page' => 10,
        'cursor' => $nextCursor,
    ]));

    $response2->assertInertia(fn ($page) => $page
        ->has('transactions.data', 10)
    );
});

test('empty results when no matches', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Coffee Shop',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'nonexistent_query_string',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 0)
    );
});

test('user scoping - cannot see other users transactions', function () {
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $otherUser->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $otherUser->id,
        'account_id' => $otherAccount->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('applied filters are returned in response', function () {
    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'test',
        'date_from' => '2025-01-01',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->where('appliedFilters.search', 'test')
        ->where('appliedFilters.date_from', '2025-01-01')
    );
});

test('filter by multiple categories including uncategorized', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => $category->id.',uncategorized',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
    );
});

test('keeps the rows of one day in one order across cursor pages', function () {
    $sameDay = Transaction::factory()->count(15)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    $firstPage = actingAs($this->user)->get(route('transactions.index', ['per_page' => 10]))
        ->viewData('page')['props']['transactions'];
    $secondPage = actingAs($this->user)->get($firstPage['next_page_url'])
        ->viewData('page')['props']['transactions'];

    expect([...array_column($firstPage['data'], 'id'), ...array_column($secondPage['data'], 'id')])
        ->toBe($sameDay->pluck('id')->sortDesc()->values()->all());
});

test('filter by amount compares each currency at its own scale', function () {
    // 50 in major units: 50 minor units in COP, 5000 in EUR, and the same
    // threshold has to catch both.
    $copAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'COP']);
    $eurAccount = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $copAccount->id,
        'currency_code' => 'COP',
        'amount' => 60, // 60 COP
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $eurAccount->id,
        'currency_code' => 'EUR',
        'amount' => 6000, // 60.00 EUR
    ]);
    // Below the threshold in its own currency, and a decoy: read at the old
    // fixed scale of 2 it would look like 40.00 and be excluded either way,
    // so the row that proves the point is the 60 COP one above.
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $copAccount->id,
        'currency_code' => 'COP',
        'amount' => 40, // 40 COP
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'amount_min' => 50,
    ]));

    $response->assertInertia(fn ($page) => $page->has('transactions.data', 2));
});

test('filter by amount still covers a currency the config no longer offers', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'currency_code' => 'ZWL',
        'amount' => 6000,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'amount_min' => 50,
    ]));

    $response->assertInertia(fn ($page) => $page->has('transactions.data', 1));
});

test('a positive amount range only matches income, so small expenses need the negative range', function () {
    foreach ([-1500, -2500, -9000, 2000] as $amount) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'currency_code' => 'USD',
            'amount' => $amount,
        ]);
    }

    actingAs($this->user)->get(route('transactions.index', ['amount_min' => 0, 'amount_max' => 30]))
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.amount', 2000));

    actingAs($this->user)->get(route('transactions.index', ['amount_min' => -30, 'amount_max' => 0]))
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 2)
            ->where('appliedFilters.amount_min', -30)
            ->where('appliedFilters.amount_max', 0));
});
