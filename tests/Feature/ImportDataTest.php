<?php

use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('import data endpoint includes automation rules with labels', function () {
    $user = User::factory()->onboarded()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'action_category_id' => $category->id,
    ]);
    $rule->labels()->attach($label->id);

    $response = actingAs($user)->getJson('/api/import/data');

    $response->assertSuccessful();
    $response->assertJsonPath('automationRules.0.category.id', $category->id);
    $response->assertJsonPath('automationRules.0.labels.0.id', $label->id);
    $response->assertJsonPath('automationRules.0.labels.0.name', $label->name);
});
