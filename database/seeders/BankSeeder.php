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
        $jsonPath = database_path('seeders/data/banks.json');
        $banks = json_decode(file_get_contents($jsonPath), true);

        foreach ($banks as $bank) {
            Bank::create([
                'name' => $bank['name'],
                'logo' => $bank['logo'],
                'user_id' => null,
            ]);
        }
    }
}
