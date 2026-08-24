<?php

namespace Database\Factories;

use App\Models\IntegrationRequest;
use App\Models\IntegrationRequestVote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationRequestVote>
 */
class IntegrationRequestVoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'integration_request_id' => IntegrationRequest::factory(),
            'user_id' => User::factory(),
        ];
    }
}
