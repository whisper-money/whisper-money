<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use App\Models\UserSetting;

test('notifications page lists the user budgets and defaults', function () {
    $user = User::factory()->create();
    Budget::factory()->create(['user_id' => $user->id, 'name' => 'Padel']);

    $response = $this->actingAs($user)->get(route('notifications.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/notifications')
        ->has('budgets', 1)
        ->where('budgetDefaults.notify_on_new_transaction', false)
        ->where('budgetDefaults.notify_on_close_to_limit', true)
        ->where('budgetDefaults.notify_on_over_limit', true)
        ->where('budgets.0.name', 'Padel'));
});

test('a settings row created without the budget columns leaves the per-transaction default off', function () {
    $user = User::factory()->create();

    // Every settings controller writes only its own column, so the rest of the
    // row falls back to the database defaults.
    $user->setting()->updateOrCreate(
        ['user_id' => $user->id],
        ['notify_on_bank_transactions_synced' => true],
    );

    expect($user->fresh()->setting)
        ->budget_notify_on_new_transaction->toBeFalse()
        ->budget_notify_on_close_to_limit->toBeTrue()
        ->budget_notify_on_over_limit->toBeTrue();
});

test('a budget notification toggle can be updated', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'notify_on_over_limit' => false,
    ]);

    $this->actingAs($user)
        ->patch(route('notifications.budgets.update', $budget), [
            'notify_on_over_limit' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($budget->fresh()->notify_on_over_limit)->toBeTrue();
});

test('a user cannot update another user budget notifications', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($intruder)
        ->patch(route('notifications.budgets.update', $budget), [
            'notify_on_over_limit' => true,
        ])
        ->assertForbidden();

    expect($budget->fresh()->notify_on_over_limit)->toBeFalse();
});

test('a new budget enables the limit notifications by default but not the per-transaction one', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post('/budgets', [
        'name' => 'Default Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ])->assertRedirect();

    $budget = Budget::where('user_id', $user->id)->firstOrFail();

    expect($budget->notify_on_new_transaction)->toBeFalse()
        ->and($budget->notify_on_close_to_limit)->toBeTrue()
        ->and($budget->notify_on_over_limit)->toBeTrue();
});

test('a new budget inherits the user notification defaults', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    UserSetting::factory()->for($user)->create([
        'budget_notify_on_new_transaction' => true,
        'budget_notify_on_close_to_limit' => false,
        'budget_notify_on_over_limit' => true,
    ]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post('/budgets', [
        'name' => 'Inherited Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ])->assertRedirect();

    $budget = Budget::where('user_id', $user->id)->firstOrFail();

    expect($budget->notify_on_new_transaction)->toBeTrue()
        ->and($budget->notify_on_close_to_limit)->toBeFalse()
        ->and($budget->notify_on_over_limit)->toBeTrue();
});
