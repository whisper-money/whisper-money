<?php

namespace Database\Factories;

use App\Enums\ImportMode;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
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
            'source' => ImportSource::Banktrack,
            'file_name' => 'banktrack-export.csv',
            'mode' => ImportMode::Add,
            'status' => ImportStatus::Completed,
            'stats' => [],
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::Draft,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }

    public function undone(): static
    {
        return $this->state(fn (array $attributes) => [
            'undone_at' => now(),
        ]);
    }
}
