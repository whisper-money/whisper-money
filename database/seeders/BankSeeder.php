<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $banks = [
            ['name' => 'Chase', 'logo' => null],
            ['name' => 'Bank of America', 'logo' => null],
            ['name' => 'Wells Fargo', 'logo' => null],
            ['name' => 'Citibank', 'logo' => null],
            ['name' => 'US Bank', 'logo' => null],
        ];

        foreach ($banks as $bank) {
            Bank::create([
                'name' => $bank['name'],
                'name_iv' => fake()->regexify('[A-Za-z0-9]{16}'),
                'logo' => $bank['logo'],
            ]);
        }
    }
}
