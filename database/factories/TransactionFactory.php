<?php

namespace Database\Factories;

use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'account_id' => Account::factory(),
            'category_id' => Category::factory(),
            'description' => fake()->sentence(),
            'description_iv' => fake()->regexify('[A-Za-z0-9]{16}'),
            'transaction_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'amount' => fake()->numberBetween(-100000, 100000),
            'currency_code' => fake()->randomElement(['USD', 'EUR', 'GBP', 'JPY']),
            'notes' => fake()->optional()->paragraph(),
            'notes_iv' => fake()->optional()->regexify('[A-Za-z0-9]{16}'),
            'source' => TransactionSource::ManuallyCreated,
        ];
    }

    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => TransactionSource::Imported,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'account_id' => Account::factory()->state([
                'user_id' => $user->id,
                'space_id' => $user->current_space_id,
            ]),
        ]);
    }

    public function enableBanking(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => TransactionSource::EnableBanking,
            'external_transaction_id' => fake()->uuid(),
            'description_iv' => null,
            'notes_iv' => null,
        ]);
    }

    /**
     * One part of a split of $original: its owner, space, account, date,
     * description, notes, currency, source and import, under it. The original
     * itself is left as it is; soft-delete it to stand for a real split.
     */
    public function splitPartOf(Transaction $original): static
    {
        return $this->state(fn (array $attributes) => [
            'split_parent_id' => $original->id,
            'user_id' => $original->user_id,
            'space_id' => $original->space_id,
            'account_id' => $original->account_id,
            'transaction_date' => $original->transaction_date,
            'description' => $original->description,
            'description_iv' => $original->description_iv,
            'notes' => $original->notes,
            'notes_iv' => $original->notes_iv,
            'currency_code' => $original->currency_code,
            'source' => $original->source,
            'import_id' => $original->import_id,
        ]);
    }

    public function plaintext(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => fake()->sentence(),
            'description_iv' => null,
            'notes' => fake()->optional()->paragraph(),
            'notes_iv' => null,
        ]);
    }
}
