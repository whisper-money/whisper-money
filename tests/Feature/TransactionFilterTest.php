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
});

test('index returns paginated transactions as Inertia props', function () {
    Transaction::factory()->plaintext()->count(3)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('transactions.data', 3)
        ->has('appliedFilters')
        ->where('appliedFilters.sort', '-transaction_date')
    );
});

test('transactions include eager-loaded relationships', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $transaction = Transaction::factory()->plaintext()->create([
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
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    Transaction::factory()->plaintext()->create([
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

test('filter by amount range', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -5000, // -$50.00
    ]);

    Transaction::factory()->plaintext()->create([
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

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->plaintext()->create([
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
        Transaction::factory()->plaintext()->create([
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

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->plaintext()->create([
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

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    Transaction::factory()->plaintext()->create([
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

    $txWithLabel = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    $txWithLabel->labels()->attach($label->id);

    Transaction::factory()->plaintext()->create([
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

test('search matches description', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store Purchase',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Gas Station',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'Grocery',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.description', 'Grocery Store Purchase')
    );
});

test('filter by creditor name', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => 'Amazon EU',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => 'Coffee Shop',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'creditor_name' => 'Amazon',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.creditor_name', 'Amazon EU')
        ->where('appliedFilters.creditor_name', 'Amazon')
    );
});

test('filter by debtor name', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'debtor_name' => 'Payroll GmbH',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'debtor_name' => 'Other Sender',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'debtor_name' => 'Payroll',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.debtor_name', 'Payroll GmbH')
        ->where('appliedFilters.debtor_name', 'Payroll')
    );
});

test('search matches counterparty names', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Card payment',
        'creditor_name' => 'Amazon EU',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Card payment',
        'creditor_name' => 'Coffee Shop',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'Amazon',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.creditor_name', 'Amazon EU')
    );
});

test('search matches notes', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Some purchase',
        'notes' => 'weekly groceries for the family',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Another purchase',
        'notes' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'groceries',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('combined filters work', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Matches all filters
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
        'transaction_date' => '2025-06-15',
        'amount' => -5000,
        'description' => 'Target purchase',
    ]);

    // Wrong date
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
        'transaction_date' => '2025-01-01',
        'amount' => -5000,
        'description' => 'Target purchase',
    ]);

    // Wrong category
    Transaction::factory()->plaintext()->create([
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

test('sort by date ascending', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-01-01',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'sort' => 'transaction_date',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.transaction_date', fn ($date) => str_contains($date, '2025-01-01'))
    );
});

test('sort by date descending (default)', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-01-01',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.transaction_date', fn ($date) => str_contains($date, '2025-06-15'))
    );
});

test('sort by amount', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -10000,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => 5000,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'sort' => 'amount',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.amount', -10000)
    );
});

test('sort by description', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Zebra Store',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Apple Store',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'sort' => 'description',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.description', 'Apple Store')
    );
});

test('cursor pagination returns correct results', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->plaintext()->count(25)->create([
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
    Transaction::factory()->plaintext()->create([
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

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    Transaction::factory()->plaintext()->create([
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
        'sort' => '-amount',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->where('appliedFilters.search', 'test')
        ->where('appliedFilters.date_from', '2025-01-01')
        ->where('appliedFilters.sort', '-amount')
    );
});

test('filter by multiple categories including uncategorized', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->plaintext()->create([
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
