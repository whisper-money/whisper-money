<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/**
 * Creates `$count` transactions for the user, stamped as created `$daysAgo`
 * days ago.
 */
function createTransactionsDaysAgo(User $user, Account $account, int $count, int $daysAgo, bool $imported = false): void
{
    $factory = Transaction::factory()->count($count);

    ($imported ? $factory->imported() : $factory)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'created_at' => now()->subDays($daysAgo),
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'type' => AccountType::Checking,
    ]);
});

test('a big old import followed by recent manual entries counts as adding by hand', function () {
    // The shape of the reference production case: thousands of rows imported
    // weeks ago, and only manual entries since.
    createTransactionsDaysAgo($this->user, $this->account, 120, daysAgo: 44, imported: true);
    createTransactionsDaysAgo($this->user, $this->account, 27, daysAgo: 35);
    createTransactionsDaysAgo($this->user, $this->account, 34, daysAgo: 5);

    expect($this->user->addsTransactionsByHand())->toBeTrue();
});

test('a mostly imported last month does not count as adding by hand', function () {
    createTransactionsDaysAgo($this->user, $this->account, 40, daysAgo: 3, imported: true);
    createTransactionsDaysAgo($this->user, $this->account, 5, daysAgo: 3);

    expect($this->user->addsTransactionsByHand())->toBeFalse();
});

test('exactly a quarter of manual entries is enough', function () {
    createTransactionsDaysAgo($this->user, $this->account, 3, daysAgo: 3, imported: true);
    createTransactionsDaysAgo($this->user, $this->account, 1, daysAgo: 3);

    expect($this->user->addsTransactionsByHand())->toBeTrue();
});

test('no transactions in the window does not count as adding by hand', function () {
    createTransactionsDaysAgo($this->user, $this->account, 10, daysAgo: 45);

    expect($this->user->addsTransactionsByHand())->toBeFalse();
});

test('deleted manual entries do not count', function () {
    createTransactionsDaysAgo($this->user, $this->account, 3, daysAgo: 3, imported: true);
    createTransactionsDaysAgo($this->user, $this->account, 2, daysAgo: 3);
    $this->user->transactions()->where('source', 'manually_created')->get()->each->delete();

    expect($this->user->addsTransactionsByHand())->toBeFalse();
});

test('other users transactions do not count', function () {
    $other = User::factory()->onboarded()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    createTransactionsDaysAgo($other, $otherAccount, 5, daysAgo: 3);
    createTransactionsDaysAgo($this->user, $this->account, 5, daysAgo: 3, imported: true);

    expect($this->user->addsTransactionsByHand())->toBeFalse();
});

test('the header button flag is shared with readers who add by hand', function () {
    createTransactionsDaysAgo($this->user, $this->account, 5, daysAgo: 3);

    actingAs($this->user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('showHeaderAddTransaction', true));
});

test('the header button flag is off for readers who only import', function () {
    createTransactionsDaysAgo($this->user, $this->account, 5, daysAgo: 3, imported: true);

    actingAs($this->user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('showHeaderAddTransaction', false));
});

test('the header button flag is off while the reader is still onboarding', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    $account = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);
    createTransactionsDaysAgo($user, $account, 5, daysAgo: 3);

    actingAs($user)->withoutVite()->get(route('onboarding'))
        ->assertInertia(fn (Assert $page) => $page->where('showHeaderAddTransaction', false));
});

test('the header button flag is off once no account can hold a transaction', function () {
    createTransactionsDaysAgo($this->user, $this->account, 5, daysAgo: 3);
    $this->account->update(['archived_at' => now()]);
    Account::factory()->create(['user_id' => $this->user->id, 'type' => AccountType::Investment]);

    actingAs($this->user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('showHeaderAddTransaction', false));
});

test('guests never get the header button', function () {
    $this->withoutVite()->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('showHeaderAddTransaction', false));
});

test('client-side visits that already hold the flag skip the query', function () {
    createTransactionsDaysAgo($this->user, $this->account, 5, daysAgo: 3);

    actingAs($this->user)->withoutVite()
        ->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Except-Once-Props' => "showHeaderAddTransaction:{$this->user->id}",
        ])
        ->assertOk()
        ->assertJsonMissingPath('props.showHeaderAddTransaction');
});

test('a flag the client cached as a guest is resolved again after login', function () {
    createTransactionsDaysAgo($this->user, $this->account, 5, daysAgo: 3);

    actingAs($this->user)->withoutVite()
        ->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Except-Once-Props' => 'showHeaderAddTransaction:guest',
        ])
        ->assertOk()
        ->assertJsonPath('props.showHeaderAddTransaction', true);
});
