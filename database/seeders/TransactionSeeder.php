<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        $accounts = Account::all();
        $categories = Category::all();

        if ($users->isEmpty() || $accounts->isEmpty() || $categories->isEmpty()) {
            return;
        }

        foreach ($accounts->take(5) as $account) {
            for ($i = 0; $i < 10; $i++) {
                Transaction::create([
                    'user_id' => $users->random()->id,
                    'account_id' => $account->id,
                    'category_id' => $categories->random()->id,
                    'description' => fake()->sentence(),
                    'description_iv' => fake()->regexify('[A-Za-z0-9]{16}'),
                    'transaction_date' => fake()->dateTimeBetween('-3 months', 'now'),
                    'amount' => fake()->randomFloat(2, -500, 500),
                    'currency_code' => 'USD',
                    'notes' => fake()->optional(0.3)->paragraph(),
                    'notes_iv' => fake()->optional(0.3)->regexify('[A-Za-z0-9]{16}'),
                ]);
            }
        }
    }
}
