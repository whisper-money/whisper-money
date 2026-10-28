<?php

use App\Enums\CategorySource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionSplitter;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function transactionsWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function transactionOn(Account $account, array $attributes = []): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'category_id' => null,
        ...$attributes,
    ]);
}

function sharedTransactionSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

describe('list', function () {
    it('lists the transactions of the personal space, most recent day first', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Main checking', 'currency_code' => 'EUR']);
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
        $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Holidays']);
        $older = transactionOn($account, ['transaction_date' => '2026-01-10']);
        $newer = transactionOn($account, [
            'transaction_date' => '2026-01-20',
            'description' => 'Market',
            'amount' => -4250,
            'currency_code' => 'EUR',
            'category_id' => $category->id,
            'category_source' => CategorySource::Manual,
            'creditor_name' => 'Fresh Market',
            'debtor_name' => null,
            'notes' => null,
        ]);
        $newer->labels()->attach($label);
        transactionOn(Account::factory()->create());

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0', [
                'id' => $newer->id,
                'space_id' => $user->personalSpace->id,
                'account' => ['id' => $account->id, 'name' => 'Main checking'],
                'transaction_date' => '2026-01-20',
                'description' => 'Market',
                'amount' => -4250,
                'currency' => 'EUR',
                'category' => ['id' => $category->id, 'name' => 'Groceries'],
                'category_source' => 'manual',
                'labels' => [['id' => $label->id, 'name' => 'Holidays']],
                'source' => 'manually_created',
                'creditor_name' => 'Fresh Market',
                'debtor_name' => null,
                'notes' => null,
                'split_parent_id' => null,
                'created_at' => $newer->created_at->toIso8601String(),
            ]);
    });

    it('filters like search_transactions', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $other = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $match = transactionOn($account, ['description' => 'Coffee shop', 'amount' => -350, 'transaction_date' => '2026-02-05', 'category_id' => $category->id]);
        $match->labels()->attach($label);
        transactionOn($account, ['description' => 'Coffee beans', 'amount' => -350, 'transaction_date' => '2026-03-05', 'category_id' => $category->id])->labels()->attach($label);
        transactionOn($account, ['description' => 'Coffee machine', 'amount' => -90_000, 'transaction_date' => '2026-02-05', 'category_id' => $category->id])->labels()->attach($label);
        transactionOn($other, ['description' => 'Coffee shop', 'amount' => -350, 'transaction_date' => '2026-02-05', 'category_id' => $category->id]);
        transactionOn($account, ['description' => 'Coffee shop', 'amount' => -350, 'transaction_date' => '2026-02-05']);

        $query = http_build_query([
            'query' => 'coffee',
            'account_id' => $account->id,
            'category_id' => $category->id,
            'label_ids' => [$label->id],
            'from' => '2026-02-01',
            'to' => '2026-02-28',
            'min_amount' => -1000,
            'max_amount' => 0,
        ]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?'.$query)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$match->id]);
    });

    it('refuses a label of another space and an invalid date', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?from=yesterday-ish&label_ids[]='.Label::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'label_ids.0']);
    });

    it('lists a shared space when asked, and refuses one the user cannot access', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));
        transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/transactions?space='.$space->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$theirs->id]);

        withHeaders($headers)
            ->getJson('/api/v1/transactions?space='.Space::factory()->create(['owner_id' => $owner->id, 'personal' => false])->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });

    it('pages through transactions sharing a day by id', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $sameDay = collect(range(1, 3))->map(fn (): Transaction => transactionOn($account, ['transaction_date' => '2026-01-20']))
            ->sortByDesc('id')->values();
        $older = transactionOn($account, ['transaction_date' => '2026-01-01']);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/transactions?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$sameDay[0]->id, $sameDay[1]->id]);

        withHeaders($headers)->getJson('/api/v1/transactions?limit=2&starting_after='.$sameDay[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$sameDay[2]->id, $older->id]);

        withHeaders($headers)->getJson('/api/v1/transactions?starting_after='.transactionOn(Account::factory()->create())->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });

    it('lists the parts of a split instead of the original', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $original = transactionOn($account, ['amount' => -5000]);
        app(TransactionSplitter::class)->split($original, [['amount' => -3000], ['amount' => -2000]]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        expect($response->json('data.*.split_parent_id'))->toBe([$original->id, $original->id])
            ->and($response->json('data.*.id'))->not->toContain($original->id);
    });
});

describe('show', function () {
    it('retrieves a transaction of the space', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions/'.$transaction->id)
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id);
    });

    it('answers 404 for a transaction of another user, another space or no transaction at all', function (string $case) {
        $user = User::factory()->create();
        $id = match ($case) {
            'other user' => transactionOn(Account::factory()->create())->id,
            'other space' => transactionOn(Account::factory()->create([
                'user_id' => $user->id,
                'space_id' => sharedTransactionSpace($user, User::factory()->create())->id,
            ]))->id,
            'missing' => (string) Str::uuid(),
        };

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions/'.$id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with(['other user', 'other space', 'missing']);
});

describe('create', function () {
    it('creates a manual transaction, defaulting the currency to the account', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);

        $response = withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => $account->id,
                'description' => 'Cash at the market',
                'amount' => -1250,
                'transaction_date' => '2026-01-15',
                'category_id' => $category->id,
                'label_ids' => [$label->id],
                'creditor_name' => 'Market',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.source', 'manually_created')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.category_source', 'manual')
            ->assertJsonPath('data.labels.0.id', $label->id)
            ->assertJsonPath('balance_updated', false);

        $transaction = Transaction::query()->findOrFail($response->json('data.id'));

        expect($transaction->amount)->toBe(-1250)
            ->and($transaction->space_id)->toBe($user->personalSpace->id)
            ->and($transaction->creditor_name)->toBe('Market');
    });

    it('moves a manual balance when asked, and leaves a connected one alone', function () {
        $user = User::factory()->create();
        $manual = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $manual->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $connected = Account::factory()->connected()->create(['user_id' => $user->id]);
        $connected->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $body = ['description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15', 'update_balance' => true];

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'account_id' => $manual->id])
            ->assertCreated()
            ->assertJsonPath('balance_updated', true);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'account_id' => $connected->id])
            ->assertCreated()
            ->assertJsonPath('balance_updated', false);

        expect($manual->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(9_900)
            ->and($connected->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(10_000);
    });

    it('validates the transaction', function () {
        $user = User::factory()->create();

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['amount' => 'a lot', 'currency_code' => 'EURO', 'colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'description', 'amount', 'transaction_date', 'currency_code', 'colour']);
    });

    it('refuses an account, category or label of another space', function () {
        $user = User::factory()->create();

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => Account::factory()->create()->id,
                'description' => 'Lunch',
                'amount' => -1000,
                'transaction_date' => '2026-01-15',
                'category_id' => Category::factory()->create()->id,
                'label_ids' => [Label::factory()->create()->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'category_id', 'label_ids.0']);

        expect(Transaction::query()->count())->toBe(0);
    });

    it('creates the transaction in the space it is asked to', function () {
        $user = User::factory()->create();
        $space = sharedTransactionSpace($user, User::factory()->create());
        $account = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'space' => $space->id,
                'account_id' => $account->id,
                'description' => 'Shared dinner',
                'amount' => -6000,
                'transaction_date' => '2026-01-15',
            ])
            ->assertCreated()
            ->assertJsonPath('data.space_id', $space->id);
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Lunch', 'amount' => -1000, 'transaction_date' => '2026-01-15'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect(Transaction::query()->count())->toBe(0);
    });

    it('replays a POST with the same idempotency key instead of creating a second transaction', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $headers = [...transactionsWriter($user), 'Idempotency-Key' => 'lunch-0115'];
        $body = ['account_id' => $account->id, 'description' => 'Lunch', 'amount' => -1000, 'transaction_date' => '2026-01-15'];

        $first = withHeaders($headers)->postJson('/api/v1/transactions', $body)->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/transactions', $body)->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and(Transaction::query()->count())->toBe(1);
    });
});

describe('update', function () {
    it('changes only the fields it is sent', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['description' => 'Old', 'amount' => -500, 'notes' => 'Keep me']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['description' => 'New', 'amount' => -700])
            ->assertOk()
            ->assertJsonPath('data.description', 'New')
            ->assertJsonPath('data.amount', -700)
            ->assertJsonPath('data.notes', 'Keep me')
            ->assertJsonPath('balance_updated', false);
    });

    it('categorizes an imported transaction as a manual choice, and clears it again', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $transaction = Transaction::factory()->imported()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => null,
            'ai_confidence' => 0.4,
        ]);
        $headers = transactionsWriter($user);

        withHeaders($headers)
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => $category->id, 'notes' => 'Checked'])
            ->assertOk()
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.category_source', 'manual')
            ->assertJsonPath('data.notes', 'Checked');

        expect($transaction->fresh()->ai_confidence)->toBeNull();

        withHeaders($headers)
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => null])
            ->assertOk()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.category_source', null);
    });

    it('refuses the core fields of an imported transaction or a split part, saving nothing', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $imported = Transaction::factory()->imported()->create(['user_id' => $user->id, 'account_id' => $account->id, 'amount' => -500]);
        $original = transactionOn($account, ['amount' => -5000]);
        $part = app(TransactionSplitter::class)->split($original, [['amount' => -3000], ['amount' => -2000]])->first();
        $headers = transactionsWriter($user);

        withHeaders($headers)
            ->patchJson('/api/v1/transactions/'.$imported->id, ['amount' => -700, 'notes' => 'Edited'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'Only manually-created transactions can change amount.']);

        withHeaders($headers)
            ->patchJson('/api/v1/transactions/'.$part->id, ['transaction_date' => '2026-01-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction_date' => 'one part of a split']);

        expect($imported->fresh()->amount)->toBe(-500)
            ->and($imported->fresh()->notes)->not->toBe('Edited');
    });

    it('moves the transaction to another account of the space, moving the manual balances', function () {
        $user = User::factory()->create();
        $from = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $to = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $from->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $to->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $transaction = transactionOn($from, ['amount' => -100, 'transaction_date' => '2026-01-15', 'currency_code' => 'EUR']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['account_id' => $to->id, 'update_balance' => true])
            ->assertOk()
            ->assertJsonPath('data.account.id', $to->id)
            ->assertJsonPath('balance_updated', true);

        expect($from->balances()->value('balance'))->toBe(10_100)
            ->and($to->balances()->value('balance'))->toBe(9_900);
    });

    it('refuses an account of another space and a field it does not take', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['account_id' => Account::factory()->create()->id, 'label_ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'label_ids']);
    });

    it('answers 404 for a transaction of another user', function () {
        $user = User::factory()->create();

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.transactionOn(Account::factory()->create())->id, ['notes' => 'Mine now'])
            ->assertNotFound();
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['notes' => null]);

        withHeaders(apiV1Bearer($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['notes' => 'Nope'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($transaction->fresh()->notes)->toBeNull();
    });
});

describe('delete', function () {
    it('deletes a manual transaction, reversing its balance when asked', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $transaction = transactionOn($account, ['amount' => -100, 'transaction_date' => '2026-01-15', 'currency_code' => $account->currency_code]);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id.'?update_balance=1')
            ->assertOk()
            ->assertExactJson(['id' => $transaction->id, 'deleted' => true, 'balance_updated' => true]);

        expect(Transaction::query()->find($transaction->id))->toBeNull()
            ->and($account->balances()->value('balance'))->toBe(10_100);
    });

    it('refuses an imported transaction and a split part', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $imported = Transaction::factory()->imported()->create(['user_id' => $user->id, 'account_id' => $account->id]);
        $part = app(TransactionSplitter::class)->split(transactionOn($account, ['amount' => -5000]), [['amount' => -3000], ['amount' => -2000]])->first();
        $headers = transactionsWriter($user);

        withHeaders($headers)
            ->deleteJson('/api/v1/transactions/'.$imported->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction' => 'Only manually-created transactions can be deleted.']);

        withHeaders($headers)
            ->deleteJson('/api/v1/transactions/'.$part->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['transaction' => 'Merge the split back first.']);

        expect(Transaction::query()->whereKey([$imported->id, $part->id])->count())->toBe(2);
    });

    it('answers 404 for a transaction of another user', function () {
        $user = User::factory()->create();
        $theirs = transactionOn(Account::factory()->create());

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$theirs->id)
            ->assertNotFound();

        expect($theirs->fresh())->not->toBeNull();
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(apiV1Bearer($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($transaction->fresh())->not->toBeNull();
    });
});
