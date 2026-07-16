<?php

use App\Features\Spaces;
use App\Models\Account;
use App\Models\Category;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

/**
 * A user with two spaces must only ever see the active space's data. These are
 * the anti-leak guardrails for the whole multi-tenant feature.
 */
function userWithTwoSpaces(): array
{
    $user = User::factory()->onboarded()->create();
    $personal = $user->personalSpace;
    $business = $user->ownedSpaces()->create(['name' => 'Acme', 'personal' => false]);

    return [$user, $personal, $business];
}

it('only lists the active space accounts', function () {
    [$user, $personal, $business] = userWithTwoSpaces();

    Account::factory()->for($user)->create(['space_id' => $personal->id, 'name' => 'Personal Checking']);
    Account::factory()->for($user)->create(['space_id' => $business->id, 'name' => 'Acme Payroll']);

    $user->forceFill(['current_space_id' => $business->id])->save();

    $this->actingAs($user)->get('/accounts')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Accounts/Index')
            ->has('accounts', 1)
            ->where('accounts.0.name', 'Acme Payroll'));

    $user->forceFill(['current_space_id' => $personal->id])->save();

    $this->actingAs($user)->get('/accounts')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.name', 'Personal Checking'));
});

it('only syncs the active space transactions', function () {
    [$user, $personal, $business] = userWithTwoSpaces();

    $personalAccount = Account::factory()->for($user)->create(['space_id' => $personal->id]);
    $businessAccount = Account::factory()->for($user)->create(['space_id' => $business->id]);

    Transaction::factory()->for($personalAccount)->create(['user_id' => $user->id]);
    $businessTx = Transaction::factory()->for($businessAccount)->create(['user_id' => $user->id]);

    $user->forceFill(['current_space_id' => $business->id])->save();

    $response = $this->actingAs($user)->getJson('/api/sync/transactions');
    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($businessTx->id)
        ->and($ids)->toHaveCount(1);
});

it('scopes categories on the settings page to the active space', function () {
    [$user, $personal, $business] = userWithTwoSpaces();

    Category::factory()->for($user)->create(['space_id' => $personal->id, 'name' => 'Personal Only']);
    Category::factory()->for($user)->create(['space_id' => $business->id, 'name' => 'Business Only']);

    $user->forceFill(['current_space_id' => $business->id])->save();

    $this->actingAs($user)->get('/settings/categories')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('categories', 1)
            ->where('categories.0.name', 'Business Only'));
});

it('cannot update a transaction that lives in another space', function () {
    [$user, $personal, $business] = userWithTwoSpaces();

    // A transaction created by someone else in a space this user cannot access.
    $stranger = User::factory()->create();
    $strangerAccount = Account::factory()->for($stranger)->create();
    $strangerSpace = $stranger->personalSpace;
    $foreignTx = Transaction::factory()->for($strangerAccount)->create([
        'user_id' => $stranger->id,
        'space_id' => $strangerSpace->id,
    ]);

    $this->actingAs($user)
        ->patchJson("/transactions/{$foreignTx->id}", ['description' => 'hacked'])
        ->assertForbidden();
});

it('exposes the accessible spaces and current space as shared props', function () {
    [$user, $personal, $business] = userWithTwoSpaces();
    Feature::for($user)->activate(Spaces::class);

    $user->forceFill(['current_space_id' => $business->id])->save();

    $this->actingAs($user)->get('/accounts')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentSpace.id', $business->id)
            ->where('currentSpace.name', 'Acme')
            ->where('features.spaces', true)
            ->has('spaces', 2));
});
