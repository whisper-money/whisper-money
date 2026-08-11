<?php

namespace Database\Factories;

use App\Models\McpToolCall;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpToolCall>
 */
class McpToolCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tool' => $this->faker->randomElement(['search_transactions', 'list_accounts', 'get_net_worth']),
        ];
    }
}
