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

    /**
     * Keep fixtures coherent with the multi-tenant model: a transaction, its
     * account and its space share one owner. When a caller sets a user_id but
     * lets the account default (or wires an account owned by someone else), we
     * realign the account to that user so space scoping in tests mirrors
     * production, where transaction.space_id always equals account.space_id.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Transaction $transaction): void {
            if ($transaction->user_id === null) {
                return;
            }

            $account = $transaction->account;

            if ($account !== null && $account->user_id !== $transaction->user_id) {
                $account->forceFill([
                    'user_id' => $transaction->user_id,
                    'space_id' => User::query()->whereKey($transaction->user_id)->value('current_space_id'),
                ])->save();

                $transaction->setRelation('account', $account);
            }
        });
    }

    public function imported(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => TransactionSource::Imported,
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
