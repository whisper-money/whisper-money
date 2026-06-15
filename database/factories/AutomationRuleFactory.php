<?php

namespace Database\Factories;

use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationRule>
 */
class AutomationRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'priority' => fake()->numberBetween(0, 100),
            'rules_json' => [
                'and' => [
                    ['>' => [['var' => 'amount'], 100]],
                    ['in' => ['grocery', ['var' => 'description']]],
                ],
            ],
            'action_category_id' => null,
            'action_note' => null,
            'action_note_iv' => null,
            'origin' => RuleOrigin::User,
        ];
    }

    /**
     * A rule created/maintained by AI auto-categorization.
     */
    public function ai(): static
    {
        return $this->state(fn (array $attributes): array => [
            'origin' => RuleOrigin::Ai,
        ]);
    }
}
