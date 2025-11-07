<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\User;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        $banks = Bank::all();

        if ($users->isEmpty() || $banks->isEmpty()) {
            return;
        }

        foreach ($users as $user) {
            Account::create([
                'user_id' => $user->id,
                'name' => 'Main Checking',
                'name_iv' => fake()->regexify('[A-Za-z0-9]{16}'),
                'bank_id' => $banks->random()->id,
                'currency_code' => 'USD',
                'type' => AccountType::Checking,
            ]);

            Account::create([
                'user_id' => $user->id,
                'name' => 'Savings Account',
                'name_iv' => fake()->regexify('[A-Za-z0-9]{16}'),
                'bank_id' => $banks->random()->id,
                'currency_code' => 'USD',
                'type' => AccountType::Savings,
            ]);
        }
    }
}
