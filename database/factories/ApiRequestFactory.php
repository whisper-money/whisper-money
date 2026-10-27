<?php

namespace Database\Factories;

use App\Enums\ApiCredential;
use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiRequest>
 */
class ApiRequestFactory extends Factory
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
            'method' => 'GET',
            'endpoint' => 'api.v1.spaces.index',
            'credential' => ApiCredential::PersonalAccessToken,
            'oauth_client_id' => null,
            'status' => 200,
        ];
    }

    public function oauth(string $clientId): static
    {
        return $this->state([
            'credential' => ApiCredential::OAuth,
            'oauth_client_id' => $clientId,
        ]);
    }
}
