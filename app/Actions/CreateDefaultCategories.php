<?php

namespace App\Actions;

use App\Models\User;

class CreateDefaultCategories
{
    /**
     * Create default categories for a newly registered user.
     */
    public function handle(User $user): void
    {
        $defaultCategories = [
            ['name' => 'Groceries', 'icon' => 'ShoppingCart', 'color' => 'green'],
            ['name' => 'Transportation', 'icon' => 'Car', 'color' => 'blue'],
            ['name' => 'Entertainment', 'icon' => 'Film', 'color' => 'purple'],
            ['name' => 'Dining Out', 'icon' => 'Utensils', 'color' => 'orange'],
            ['name' => 'Healthcare', 'icon' => 'Heart', 'color' => 'red'],
            ['name' => 'Utilities', 'icon' => 'Zap', 'color' => 'yellow'],
            ['name' => 'Shopping', 'icon' => 'ShoppingBag', 'color' => 'pink'],
            ['name' => 'Travel', 'icon' => 'Plane', 'color' => 'sky'],
        ];

        foreach ($defaultCategories as $category) {
            $user->categories()->create($category);
        }
    }
}
