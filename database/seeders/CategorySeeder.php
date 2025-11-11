<?php

namespace Database\Seeders;

use App\Actions\CreateDefaultCategories;
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

        $categories = CreateDefaultCategories::getDefaultCategories();

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
