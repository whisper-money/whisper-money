<?php

use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\User;

test('user can view their automation rules', function () {
    $user = User::factory()->create();
    $rule = AutomationRule::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('automation-rules.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/automation-rules')
        ->has('rules', 1)
    );
});

test('user can create an automation rule with category action', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->post(route('automation-rules.store'), [
            'title' => 'Grocery Rule',
            'priority' => 10,
            'rules_json' => json_encode(['in' => ['grocery', ['var' => 'description']]]),
            'action_category_id' => $category->id,
            'action_note' => null,
            'action_note_iv' => null,
        ]);

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Grocery Rule',
        'priority' => 10,
        'action_category_id' => $category->id,
    ]);
});

test('user can create an automation rule with note action', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->post(route('automation-rules.store'), [
            'title' => 'Note Rule',
            'priority' => 5,
            'rules_json' => json_encode(['==' => [['var' => 'amount'], 100]]),
            'action_category_id' => null,
            'action_note' => 'encrypted_note',
            'action_note_iv' => 'test_iv',
        ]);

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Note Rule',
        'action_note' => 'encrypted_note',
    ]);
});

test('user can create an automation rule with both actions', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->post(route('automation-rules.store'), [
            'title' => 'Combined Rule',
            'priority' => 0,
            'rules_json' => json_encode(['>' => [['var' => 'amount'], 50]]),
            'action_category_id' => $category->id,
            'action_note' => 'encrypted_note',
            'action_note_iv' => 'test_iv',
        ]);

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Combined Rule',
        'action_category_id' => $category->id,
        'action_note' => 'encrypted_note',
    ]);
});

test('user cannot create rule without at least one action', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('automation-rules.store'), [
        'title' => 'Invalid Rule',
        'priority' => 0,
        'rules_json' => json_encode(['>' => [['var' => 'amount'], 50]]),
        'action_category_id' => null,
        'action_note' => null,
        'action_note_iv' => null,
    ]);

    $response->assertSessionHasErrors('action_category_id');
});

test('user cannot create rule with invalid json logic', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post(route('automation-rules.store'), [
        'title' => 'Invalid JSON Rule',
        'priority' => 0,
        'rules_json' => 'invalid json',
        'action_category_id' => $category->id,
    ]);

    $response->assertSessionHasErrors('rules_json');
});

test('user can update their automation rule', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Old Title',
    ]);

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->patch(route('automation-rules.update', $rule), [
            'title' => 'New Title',
            'priority' => 20,
            'rules_json' => json_encode(['==' => [['var' => 'bank_name'], 'Chase']]),
            'action_category_id' => $category->id,
            'action_note' => null,
            'action_note_iv' => null,
        ]);

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertDatabaseHas('automation_rules', [
        'id' => $rule->id,
        'title' => 'New Title',
        'priority' => 20,
    ]);
});

test('user cannot update another users automation rule', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $rule = AutomationRule::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->patch(route('automation-rules.update', $rule), [
        'title' => 'Hacked Title',
        'priority' => 0,
        'rules_json' => json_encode(['==' => [1, 1]]),
        'action_category_id' => $category->id,
    ]);

    $response->assertForbidden();
});

test('user can soft delete their automation rule', function () {
    $user = User::factory()->create();
    $rule = AutomationRule::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->delete(route('automation-rules.destroy', $rule));

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertSoftDeleted('automation_rules', ['id' => $rule->id]);
});

test('user cannot delete another users automation rule', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $rule = AutomationRule::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->delete(route('automation-rules.destroy', $rule));

    $response->assertForbidden();
    $this->assertDatabaseHas('automation_rules', ['id' => $rule->id]);
});

test('rules are ordered by priority', function () {
    $user = User::factory()->create();
    AutomationRule::factory()->create(['user_id' => $user->id, 'priority' => 30]);
    AutomationRule::factory()->create(['user_id' => $user->id, 'priority' => 10]);
    AutomationRule::factory()->create(['user_id' => $user->id, 'priority' => 20]);

    $response = $this->actingAs($user)->get(route('automation-rules.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->has('rules', 3)
        ->where('rules.0.priority', 10)
        ->where('rules.1.priority', 20)
        ->where('rules.2.priority', 30)
    );
});

test('user cannot use another users category in rule', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherCategory = Category::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->post(route('automation-rules.store'), [
        'title' => 'Invalid Category Rule',
        'priority' => 0,
        'rules_json' => json_encode(['==' => [1, 1]]),
        'action_category_id' => $otherCategory->id,
    ]);

    $response->assertSessionHasErrors('action_category_id');
});

test('rules with description filter are case insensitive with lowercase rule', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->post(route('automation-rules.store'), [
            'title' => 'Case Insensitive Description Rule (lowercase)',
            'priority' => 10,
            'rules_json' => json_encode(['in' => ['m3 sport', ['var' => 'description']]]),
            'action_category_id' => $category->id,
            'action_note' => null,
            'action_note_iv' => null,
        ]);

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Case Insensitive Description Rule (lowercase)',
    ]);
});

test('rules with description filter are case insensitive with uppercase rule', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->post(route('automation-rules.store'), [
            'title' => 'Case Insensitive Description Rule (uppercase)',
            'priority' => 10,
            'rules_json' => json_encode(['in' => ['M3 SPORT', ['var' => 'description']]]),
            'action_category_id' => $category->id,
            'action_note' => null,
            'action_note_iv' => null,
        ]);

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Case Insensitive Description Rule (uppercase)',
    ]);
});

test('rules with description filter are case insensitive with mixed case rule', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->post(route('automation-rules.store'), [
            'title' => 'Case Insensitive Description Rule (mixed)',
            'priority' => 10,
            'rules_json' => json_encode(['in' => ['M3 Sport Academy', ['var' => 'description']]]),
            'action_category_id' => $category->id,
            'action_note' => null,
            'action_note_iv' => null,
        ]);

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Case Insensitive Description Rule (mixed)',
    ]);
});

test('rules with notes filter are case insensitive', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->from(route('automation-rules.index'))
        ->post(route('automation-rules.store'), [
            'title' => 'Case Insensitive Notes Rule',
            'priority' => 10,
            'rules_json' => json_encode(['in' => ['IMPORTANT NOTE', ['var' => 'notes']]]),
            'action_category_id' => $category->id,
            'action_note' => null,
            'action_note_iv' => null,
        ]);

    $response->assertRedirect(route('automation-rules.index'));
    $this->assertDatabaseHas('automation_rules', [
        'user_id' => $user->id,
        'title' => 'Case Insensitive Notes Rule',
    ]);
});
