<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryTree;

test('a child category inherits its parent type and cashflow direction', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Transfers',
        'type' => CategoryType::Transfer,
        'cashflow_direction' => CategoryCashflowDirection::Inflow,
    ]);

    $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Family transfers',
        'parent_id' => $parent->id,
        'icon' => 'Users',
        'color' => 'blue',
        'type' => CategoryType::Expense->value,
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
    ])->assertRedirect(route('categories.index'));

    $this->assertDatabaseHas('categories', [
        'user_id' => $user->id,
        'name' => 'Family transfers',
        'parent_id' => $parent->id,
        'type' => CategoryType::Transfer->value,
        'cashflow_direction' => CategoryCashflowDirection::Inflow->value,
    ]);
});

test('categories cannot be nested deeper than the max depth', function () {
    $user = User::factory()->create();
    $root = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
    $level2 = Category::factory()->childOf($root)->create(['user_id' => $user->id]);

    // Third level is allowed.
    $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Level three',
        'parent_id' => $level2->id,
        'icon' => 'Tag',
        'color' => 'blue',
        'type' => CategoryType::Expense->value,
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
    ])->assertRedirect(route('categories.index'));

    $level3 = $user->categories()->where('name', 'Level three')->firstOrFail();

    // Fourth level is rejected.
    $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Level four',
        'parent_id' => $level3->id,
        'icon' => 'Tag',
        'color' => 'blue',
        'type' => CategoryType::Expense->value,
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
    ])->assertSessionHasErrors(['parent_id']);
});

test('a category cannot be moved under one of its own descendants', function () {
    $user = User::factory()->create();
    $root = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
    $child = Category::factory()->childOf($root)->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch(route('categories.update', $root), [
        'name' => $root->name,
        'parent_id' => $child->id,
        'icon' => 'Tag',
        'color' => 'blue',
        'type' => CategoryType::Expense->value,
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
    ])->assertSessionHasErrors(['parent_id']);
});

test('the same name is allowed under different parents but blocked under the same parent', function () {
    $user = User::factory()->create();
    $food = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food', 'type' => CategoryType::Expense]);
    $drinks = Category::factory()->create(['user_id' => $user->id, 'name' => 'Drinks', 'type' => CategoryType::Expense]);

    Category::factory()->childOf($food)->create(['user_id' => $user->id, 'name' => 'Coffee']);

    // Same name under a different parent: allowed.
    $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Coffee',
        'parent_id' => $drinks->id,
        'icon' => 'Tag',
        'color' => 'blue',
        'type' => CategoryType::Expense->value,
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
    ])->assertRedirect(route('categories.index'));

    // Same name under the same parent: blocked.
    $this->actingAs($user)->post(route('categories.store'), [
        'name' => 'Coffee',
        'parent_id' => $food->id,
        'icon' => 'Tag',
        'color' => 'blue',
        'type' => CategoryType::Expense->value,
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
    ])->assertSessionHasErrors(['name']);

    expect($user->categories()->where('name', 'Coffee')->count())->toBe(2);
});

test('deleting a parent lifts its children up to the grandparent', function () {
    $user = User::factory()->create();
    $root = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
    $parent = Category::factory()->childOf($root)->create(['user_id' => $user->id]);
    $child = Category::factory()->childOf($parent)->create(['user_id' => $user->id]);

    $this->actingAs($user)->delete(route('categories.destroy', $parent))
        ->assertRedirect(route('categories.index'));

    $this->assertSoftDeleted('categories', ['id' => $parent->id]);
    expect($child->refresh()->parent_id)->toBe($root->id);
});

test('deleting a parent with the promote strategy turns its children into roots', function () {
    $user = User::factory()->create();
    $root = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
    $child = Category::factory()->childOf($root)->create(['user_id' => $user->id]);

    $this->actingAs($user)->delete(route('categories.destroy', $root), ['strategy' => 'promote'])
        ->assertRedirect(route('categories.index'));

    $this->assertSoftDeleted('categories', ['id' => $root->id]);
    expect($child->refresh()->parent_id)->toBeNull();
});

test('deleting a parent with the cascade strategy removes the subtree and uncategorizes transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $root = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
    $child = Category::factory()->childOf($root)->create(['user_id' => $user->id]);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $child->id,
    ]);

    $this->actingAs($user)->delete(route('categories.destroy', $root), ['strategy' => 'cascade'])
        ->assertRedirect(route('categories.index'));

    $this->assertSoftDeleted('categories', ['id' => $root->id]);
    $this->assertSoftDeleted('categories', ['id' => $child->id]);
    expect($transaction->refresh()->category_id)->toBeNull();
});

test('deleting a parent with the promote strategy rejects child name collisions at the top level', function () {
    $user = User::factory()->create();
    Category::factory()->create(['user_id' => $user->id, 'name' => 'Other', 'type' => CategoryType::Expense]);
    $root = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food', 'type' => CategoryType::Expense]);
    $child = Category::factory()->childOf($root)->create(['user_id' => $user->id, 'name' => 'Other']);

    $this->actingAs($user)->delete(route('categories.destroy', $root), ['strategy' => 'promote'])
        ->assertSessionHasErrors(['strategy']);

    $this->assertNotSoftDeleted('categories', ['id' => $root->id]);
    expect($child->refresh()->parent_id)->toBe($root->id);
});

test('deleting a parent with the reparent strategy rejects child name collisions at the destination level', function () {
    $user = User::factory()->create();
    $root = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food', 'type' => CategoryType::Expense]);
    Category::factory()->childOf($root)->create(['user_id' => $user->id, 'name' => 'Other']);
    $parent = Category::factory()->childOf($root)->create(['user_id' => $user->id, 'name' => 'Eating out']);
    $child = Category::factory()->childOf($parent)->create(['user_id' => $user->id, 'name' => 'Other']);

    $this->actingAs($user)->delete(route('categories.destroy', $parent))
        ->assertSessionHasErrors(['strategy']);

    $this->assertNotSoftDeleted('categories', ['id' => $parent->id]);
    expect($child->refresh()->parent_id)->toBe($parent->id);
});

test('updating a parent type cascades to all descendants', function () {
    $user = User::factory()->create();
    $root = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Hidden,
    ]);
    $child = Category::factory()->childOf($root)->create(['user_id' => $user->id]);
    $grandchild = Category::factory()->childOf($child)->create(['user_id' => $user->id]);

    $this->actingAs($user)->patch(route('categories.update', $root), [
        'name' => $root->name,
        'parent_id' => null,
        'icon' => 'Tag',
        'color' => 'blue',
        'type' => CategoryType::Income->value,
        'cashflow_direction' => CategoryCashflowDirection::Hidden->value,
    ])->assertRedirect(route('categories.index'));

    expect($child->refresh()->type)->toBe(CategoryType::Income)
        ->and($grandchild->refresh()->type)->toBe(CategoryType::Income);
});

test('the tree service expands a parent id to include all descendants', function () {
    $user = User::factory()->create();
    $root = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
    $child = Category::factory()->childOf($root)->create(['user_id' => $user->id]);
    $grandchild = Category::factory()->childOf($child)->create(['user_id' => $user->id]);
    $unrelated = Category::factory()->create(['user_id' => $user->id]);

    $expanded = (new CategoryTree)->expand($user->current_space_id, [$root->id]);

    expect($expanded)->toContain($root->id, $child->id, $grandchild->id)
        ->not->toContain($unrelated->id);
});
