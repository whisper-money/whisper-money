<?php

use App\Enums\BudgetExpenseType;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function budgetPayload(User $user, array $overrides = []): array
{
    return [
        'name' => 'Rent',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [Category::factory()->create(['user_id' => $user->id])->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
        ...$overrides,
    ];
}

test('a new budget stores the expense type the user picks', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $this->actingAs($user)
        ->post('/budgets', budgetPayload($user, ['expense_type' => 'fixed']))
        ->assertRedirect();

    expect(Budget::query()->whereBelongsTo($user)->sole()->expense_type)->toBe(BudgetExpenseType::Fixed);
});

test('a new budget counts as variable spending when no expense type is sent', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $this->actingAs($user)
        ->post('/budgets', budgetPayload($user))
        ->assertRedirect();

    expect(Budget::query()->whereBelongsTo($user)->sole()->expense_type)->toBe(BudgetExpenseType::Variable);
});

test('a catch-all budget ignores the expense type and stays variable', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $this->actingAs($user)
        ->post('/budgets', budgetPayload($user, [
            'category_ids' => [],
            'is_catch_all' => true,
            'expense_type' => 'fixed',
        ]))
        ->assertRedirect();

    expect(Budget::query()->whereBelongsTo($user)->sole()->expense_type)->toBe(BudgetExpenseType::Variable);
});

test('creating a budget rejects an unknown expense type', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $this->actingAs($user)
        ->post('/budgets', budgetPayload($user, ['expense_type' => 'occasional']))
        ->assertSessionHasErrors('expense_type');

    expect(Budget::query()->whereBelongsTo($user)->exists())->toBeFalse();
});

test('the expense type can be changed after the budget is created', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch("/budgets/{$budget->id}", ['expense_type' => 'fixed'])
        ->assertRedirect();

    expect($budget->fresh()->expense_type)->toBe(BudgetExpenseType::Fixed);
});

test('editing a budget without an expense type keeps the one it has', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->for($user)->fixed()->create();

    $this->actingAs($user)
        ->patch("/budgets/{$budget->id}", ['name' => 'Garage'])
        ->assertRedirect();

    expect($budget->fresh())
        ->name->toBe('Garage')
        ->expense_type->toBe(BudgetExpenseType::Fixed);
});

test('editing a catch-all budget leaves its expense type alone', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->for($user)->catchAll()->create();

    $this->actingAs($user)
        ->patch("/budgets/{$budget->id}", ['expense_type' => 'fixed'])
        ->assertRedirect();

    expect($budget->fresh()->expense_type)->toBe(BudgetExpenseType::Variable);
});

test('editing a budget rejects an unknown expense type', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->for($user)->create();

    $this->actingAs($user)
        ->patch("/budgets/{$budget->id}", ['expense_type' => 'occasional'])
        ->assertSessionHasErrors('expense_type');

    expect($budget->fresh()->expense_type)->toBe(BudgetExpenseType::Variable);
});

test('the budgets index sends each budget expense type', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Budget::factory()->for($user)->fixed()->create();

    $this->actingAs($user)
        ->get('/budgets')
        ->assertInertia(fn ($page) => $page->where('budgets.0.expense_type', 'fixed'));
});
