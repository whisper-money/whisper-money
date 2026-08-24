<?php

namespace Database\Factories;

use App\Enums\RuleSuggestionStatus;
use App\Models\RuleSuggestion;
use App\Models\SuggestionRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RuleSuggestion>
 */
class RuleSuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = $this->faker->unique()->company();

        return [
            'suggestion_run_id' => SuggestionRun::factory(),
            'group_key' => mb_strtolower($token),
            'match_field' => 'description',
            'match_operator' => 'contains',
            'match_token' => mb_strtolower($token),
            'proposed_category_id' => null,
            'new_category_name' => null,
            'new_category_parent_id' => null,
            'new_category_direction' => null,
            'confidence' => $this->faker->randomFloat(3, 0.7, 1),
            'group_size' => $this->faker->numberBetween(3, 30),
            'sample_descriptions' => [$token.' 1234', $token.' 5678'],
            'status' => RuleSuggestionStatus::Pending,
        ];
    }

    public function proposesNewCategory(string $name = 'Pet care', string $direction = 'outflow'): static
    {
        return $this->state(fn (): array => [
            'proposed_category_id' => null,
            'new_category_name' => $name,
            'new_category_direction' => $direction,
        ]);
    }
}
