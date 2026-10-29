<?php

use App\Enums\LabelSource;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\MergeTransactionSplits;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SplitTransaction;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateTransaction;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionSplitter;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;

/*
 * The MCP server mirrors the web app's permissions: inside a shared space a
 * member reads, changes and references only their own rows.
 */

/**
 * A shared space owned by $owner that $member joined.
 */
function mcpSharedSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

/**
 * @param  class-string  $tool
 * @param  array<string, mixed>  $arguments
 */
function callOwnRowsTool(User $user, string $tool, array $arguments): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

it('lists only the caller\'s own accounts of a shared space', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $mine = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'name' => 'Mine']);
    Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);

    callOwnRowsTool($user, ListAccounts::class, ['space' => $space->id])
        ->assertOk()
        ->assertSee($mine->id)
        ->assertDontSee('Theirs');
});

it('refuses a balance on another member\'s account of a shared space', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $theirs = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'type' => 'checking']);
    $mine = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'type' => 'checking']);

    callOwnRowsTool($user, CreateBalance::class, ['account_id' => $theirs->id, 'space' => $space->id, 'balance' => 1_000])
        ->assertHasErrors(["No account with id {$theirs->id} in space {$space->id}."]);

    callOwnRowsTool($user, CreateBalance::class, ['account_id' => $mine->id, 'space' => $space->id, 'balance' => 1_000])
        ->assertOk();

    expect($theirs->balances()->count())->toBe(0)
        ->and($mine->balances()->count())->toBe(1);
});

it('searches only the caller\'s own transactions of a shared space', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $mine = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id])->id]);
    $theirs = Transaction::factory()->create(['user_id' => $owner->id, 'account_id' => Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id]);

    callOwnRowsTool($user, SearchTransactions::class, ['space' => $space->id])
        ->assertOk()
        ->assertSee($mine->id)
        ->assertDontSee($theirs->id);
});

it('refuses to change, categorize or delete another member\'s transaction', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $theirs = Transaction::factory()->create(['user_id' => $owner->id, 'account_id' => Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id, 'notes' => null]);
    $notFound = "No transaction with id {$theirs->id} in space {$space->id}.";

    callOwnRowsTool($user, UpdateTransaction::class, ['transaction_id' => $theirs->id, 'space' => $space->id, 'notes' => 'Mine now'])
        ->assertHasErrors([$notFound]);
    callOwnRowsTool($user, CategorizeTransaction::class, ['transaction_id' => $theirs->id, 'space' => $space->id, 'category_id' => null])
        ->assertHasErrors([$notFound]);
    callOwnRowsTool($user, DeleteTransaction::class, ['transaction_id' => $theirs->id, 'space' => $space->id])
        ->assertHasErrors([$notFound]);

    expect($theirs->fresh()->notes)->toBeNull()
        ->and($theirs->fresh()->trashed())->toBeFalse();
});

it('refuses another member\'s account and category on a new transaction, and takes your own', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $theirAccount = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $theirCategory = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $myAccount = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $myCategory = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $transaction = ['space' => $space->id, 'description' => 'Groceries', 'amount' => -2000, 'transaction_date' => '2026-01-15'];

    callOwnRowsTool($user, CreateTransaction::class, [...$transaction, 'account_id' => $theirAccount->id])
        ->assertHasErrors(["No account with id {$theirAccount->id} in space {$space->id}."]);
    callOwnRowsTool($user, CreateTransaction::class, [...$transaction, 'account_id' => $myAccount->id, 'category_id' => $theirCategory->id])
        ->assertHasErrors(["No category with id {$theirCategory->id} in space {$space->id}."]);
    callOwnRowsTool($user, CreateTransaction::class, [...$transaction, 'account_id' => $myAccount->id, 'category_id' => $myCategory->id])
        ->assertOk();

    expect($theirAccount->transactions()->count())->toBe(0)
        ->and($myAccount->transactions()->count())->toBe(1);
});

it('refuses to label, split or merge another member\'s transaction, and their labels and categories on your own', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $theirAccount = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $theirs = Transaction::factory()->create(['user_id' => $owner->id, 'account_id' => $theirAccount->id, 'amount' => -5000]);
    $theirParts = app(TransactionSplitter::class)->split(
        Transaction::factory()->create(['user_id' => $owner->id, 'account_id' => $theirAccount->id, 'amount' => -5000]),
        [['amount' => -3000], ['amount' => -2000]],
    );
    $mine = Transaction::factory()->create(['user_id' => $user->id, 'account_id' => Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id])->id, 'amount' => -5000]);
    $theirLabel = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $theirCategory = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $myLabel = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

    callOwnRowsTool($user, LabelTransaction::class, ['transaction_id' => $theirs->id, 'space' => $space->id, 'add_label_ids' => [$myLabel->id]])
        ->assertHasErrors(["No transaction with id {$theirs->id} in space {$space->id}."]);
    callOwnRowsTool($user, SplitTransaction::class, ['transaction_id' => $theirs->id, 'space' => $space->id, 'splits' => [['amount' => -3000], ['amount' => -2000]]])
        ->assertHasErrors(["No transaction with id {$theirs->id} in space {$space->id}."]);
    callOwnRowsTool($user, MergeTransactionSplits::class, ['transaction_id' => $theirParts->first()->id, 'space' => $space->id])
        ->assertHasErrors(['No transaction with id']);
    callOwnRowsTool($user, LabelTransaction::class, ['transaction_id' => $mine->id, 'space' => $space->id, 'add_label_ids' => [$theirLabel->id]])
        ->assertHasErrors(["One or more label ids are not labels of yours in space {$space->id}."]);
    callOwnRowsTool($user, SplitTransaction::class, ['transaction_id' => $mine->id, 'space' => $space->id, 'splits' => [['amount' => -3000, 'category_id' => $theirCategory->id], ['amount' => -2000]]])
        ->assertHasErrors(["One or more category ids are not yours in space {$space->id}."]);
    callOwnRowsTool($user, LabelTransaction::class, ['transaction_id' => $mine->id, 'space' => $space->id, 'add_label_ids' => [$myLabel->id]])
        ->assertOk();

    expect($theirs->fresh()->labels()->count())->toBe(0)
        ->and($mine->fresh()->isSplitPart())->toBeFalse()
        ->and($mine->labels()->pluck('labels.id')->all())->toBe([$myLabel->id]);
});

it('lists only the caller\'s own categories and labels of a shared space', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'name' => 'My category']);
    Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Their category']);
    Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'name' => 'My label']);
    Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Their label']);

    callOwnRowsTool($user, ListCategories::class, ['space' => $space->id])
        ->assertOk()->assertSee('My category')->assertDontSee('Their category');
    callOwnRowsTool($user, ListLabels::class, ['space' => $space->id])
        ->assertOk()->assertSee('My label')->assertDontSee('Their label');
});

it('refuses to change or delete another member\'s category or label, or nest under their category', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $theirCategory = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);
    $theirLabel = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);

    callOwnRowsTool($user, UpdateCategory::class, ['category_id' => $theirCategory->id, 'space' => $space->id, 'name' => 'Mine'])
        ->assertHasErrors(["No category with id {$theirCategory->id} in space {$space->id}."]);
    callOwnRowsTool($user, DeleteCategory::class, ['category_id' => $theirCategory->id, 'space' => $space->id])
        ->assertHasErrors(["No category with id {$theirCategory->id} in space {$space->id}."]);
    callOwnRowsTool($user, UpdateLabel::class, ['label_id' => $theirLabel->id, 'space' => $space->id, 'name' => 'Mine'])
        ->assertHasErrors(["No label with id {$theirLabel->id} in space {$space->id}."]);
    callOwnRowsTool($user, DeleteLabel::class, ['label_id' => $theirLabel->id, 'space' => $space->id])
        ->assertHasErrors(["No label with id {$theirLabel->id} in space {$space->id}."]);
    callOwnRowsTool($user, CreateCategory::class, ['space' => $space->id, 'name' => 'Child', 'icon' => 'Home', 'color' => 'red', 'type' => 'expense', 'parent_id' => $theirCategory->id])
        ->assertHasErrors(["No category with id {$theirCategory->id} in space {$space->id}."]);

    expect($theirCategory->fresh()->name)->toBe('Theirs')
        ->and($theirLabel->fresh()->name)->toBe('Theirs')
        ->and($user->categories()->count())->toBe(0);
});

it('refuses to rename or delete the label of a savings goal, like the API', function () {
    $user = User::factory()->create();
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'New car', 'color' => 'blue', 'source' => LabelSource::SavingsGoal]);

    callOwnRowsTool($user, UpdateLabel::class, ['label_id' => $label->id, 'name' => 'Old car'])
        ->assertHasErrors(['renamed with the goal']);
    callOwnRowsTool($user, DeleteLabel::class, ['label_id' => $label->id])
        ->assertHasErrors(['it can only go away with the goal']);
    callOwnRowsTool($user, UpdateLabel::class, ['label_id' => $label->id, 'color' => 'red'])
        ->assertOk();

    expect($label->fresh())->name->toBe('New car')->color->toBe('red');
});
