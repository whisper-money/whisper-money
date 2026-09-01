<?php

use App\Models\Budget;
use App\Models\SavingsGoal;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('reordering numbers budgets and savings goals in one sequence', function () {
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $goal = SavingsGoal::factory()->create(['user_id' => $this->user->id]);
    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->patch(route('planning.reorder'), ['items' => [
        ['type' => 'goal', 'id' => $goal->id],
        ['type' => 'budget', 'id' => $otherBudget->id],
        ['type' => 'budget', 'id' => $budget->id],
    ]])->assertRedirect();

    expect($goal->fresh()->position)->toBe(0);
    expect($otherBudget->fresh()->position)->toBe(1);
    expect($budget->fresh()->position)->toBe(2);
});

test('the planning list is served in the stored order', function () {
    $first = Budget::factory()->create(['user_id' => $this->user->id, 'position' => 1]);
    $second = Budget::factory()->create(['user_id' => $this->user->id, 'position' => 0]);
    $goal = SavingsGoal::factory()->create(['user_id' => $this->user->id, 'position' => 3]);
    $otherGoal = SavingsGoal::factory()->create(['user_id' => $this->user->id, 'position' => 2]);

    $this->get(route('budgets.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('budgets.0.id', $second->id)
            ->where('budgets.1.id', $first->id)
            ->where('savingsGoals.0.id', $otherGoal->id)
            ->where('savingsGoals.1.id', $goal->id)
        );
});

test('a budget keeps a null position until it is dragged', function () {
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $goal = SavingsGoal::factory()->create(['user_id' => $this->user->id]);

    expect($budget->fresh()->position)->toBeNull();
    expect($goal->fresh()->position)->toBeNull();
});

test('users cannot reorder a budget they do not own', function () {
    $other = Budget::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->patch(route('planning.reorder'), ['items' => [
        ['type' => 'budget', 'id' => $other->id],
    ]])->assertSessionHasErrors('items.0.id');

    expect($other->fresh()->position)->toBeNull();
});

test('users cannot reorder a savings goal they do not own', function () {
    $other = SavingsGoal::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->patch(route('planning.reorder'), ['items' => [
        ['type' => 'goal', 'id' => $other->id],
    ]])->assertSessionHasErrors('items.0.id');

    expect($other->fresh()->position)->toBeNull();
});

test('an id has to exist in the table its own type names', function () {
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->patch(route('planning.reorder'), ['items' => [
        ['type' => 'goal', 'id' => $budget->id],
    ]])->assertSessionHasErrors('items.0.id');

    expect($budget->fresh()->position)->toBeNull();
});

test('an unknown type is rejected', function () {
    $budget = Budget::factory()->create(['user_id' => $this->user->id]);

    $this->patch(route('planning.reorder'), ['items' => [
        ['type' => 'account', 'id' => $budget->id],
    ]])->assertSessionHasErrors('items.0.type');
});
