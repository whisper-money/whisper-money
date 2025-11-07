<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();

        if ($users->isEmpty()) {
            return;
        }

        $categories = [
            ['name' => 'Food & Dining', 'icon' => '🍔', 'color' => '#FF6B6B'],
            ['name' => 'Transportation', 'icon' => '🚗', 'color' => '#4ECDC4'],
            ['name' => 'Entertainment', 'icon' => '🎮', 'color' => '#95E1D3'],
            ['name' => 'Shopping', 'icon' => '🛒', 'color' => '#F38181'],
            ['name' => 'Healthcare', 'icon' => '💊', 'color' => '#AA96DA'],
            ['name' => 'Utilities', 'icon' => '💡', 'color' => '#FCBAD3'],
            ['name' => 'Travel', 'icon' => '✈️', 'color' => '#FFFFD2'],
            ['name' => 'Education', 'icon' => '📚', 'color' => '#A8D8EA'],
        ];

        foreach ($users as $user) {
            foreach ($categories as $category) {
                Category::create([
                    'name' => $category['name'],
                    'icon' => $category['icon'],
                    'color' => $category['color'],
                    'user_id' => $user->id,
                ]);
            }
        }
    }
}
