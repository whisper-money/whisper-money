<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * A member of a shared space must be able to actually work in it — view detail
 * pages and edit data whose user_id is the owner's — and must lose that access
 * the moment they're removed.
 */
function memberOf(): array
{
    $owner = User::factory()->onboarded()->create();
    $space = $owner->ownedSpaces()->create(['name' => 'Acme', 'personal' => false]);

    $member = User::factory()->onboarded()->create();
    $space->members()->attach($member->id, ['role' => 'member']);
    $member->forceFill(['current_space_id' => $space->id])->save();

    return [$owner, $space, $member];
}

it('lets a member open an owner-owned account detail page', function () {
    [$owner, $space, $member] = memberOf();
    $account = Account::factory()->for($owner)->create(['space_id' => $space->id]);

    $this->actingAs($member)->get("/accounts/{$account->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Accounts/Show'));
});

it('lets a member recategorize a transaction using the space\'s category', function () {
    [$owner, $space, $member] = memberOf();
    $account = Account::factory()->for($owner)->create(['space_id' => $space->id]);
    $transaction = Transaction::factory()->for($account)->create(['user_id' => $owner->id]);
    $category = Category::factory()->for($owner)->create(['space_id' => $space->id]);

    // Exercises both the space-membership policy and the space-scoped ownership
    // validation on category_id (which used to be user_id-scoped → 422 here).
    $this->actingAs($member)
        ->patchJson("/transactions/{$transaction->id}", ['category_id' => $category->id])
        ->assertOk();

    expect($transaction->fresh()->category_id)->toBe($category->id);
});

it('denies a removed member access to the space\'s data', function () {
    [$owner, $space, $member] = memberOf();
    $account = Account::factory()->for($owner)->create(['space_id' => $space->id]);

    $space->members()->detach($member->id);

    $this->actingAs($member)->get("/accounts/{$account->id}")
        ->assertForbidden();
});
