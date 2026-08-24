<?php

namespace Database\Factories;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Food', 'Transport', 'Entertainment', 'Shopping', 'Healthcare', 'Utilities', 'Travel', 'Education', 'Bills', 'Investments', 'Savings', 'Insurance', 'Gifts', 'Personal Care', 'Sports', 'Hobbies', 'Subscriptions', 'Dining', 'Groceries', 'Clothing']),
            'icon' => fake()->randomElement(['AlertCircle', 'AlertTriangle', 'ArrowDownCircle', 'ArrowLeftRight', 'ArrowUpCircle', 'Baby', 'Banknote', 'Briefcase', 'Building', 'Building2', 'Car', 'Clock', 'CreditCard', 'Dices', 'FileText', 'Gift', 'GraduationCap', 'HandHeart', 'Heart', 'HelpCircle', 'Home', 'Landmark', 'Mail', 'PiggyBank', 'Plane', 'Receipt', 'ReceiptText', 'Repeat', 'RotateCcw', 'Scale', 'Shield', 'ShieldCheck', 'ShoppingBag', 'TrendingUp', 'Undo2', 'Users', 'Users2', 'Utensils', 'Wallet']),
            'color' => fake()->randomElement(['amber', 'blue', 'cyan', 'emerald', 'gray', 'green', 'indigo', 'orange', 'pink', 'purple', 'red', 'slate', 'teal', 'yellow']),
            'type' => fake()->randomElement(CategoryType::cases()),
            'cashflow_direction' => CategoryCashflowDirection::Hidden,
            'user_id' => User::factory(),
            'parent_id' => null,
        ];
    }

    /**
     * Nest the category under a parent, inheriting its owner and type.
     */
    public function childOf(Category $parent): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->id,
            'user_id' => $parent->user_id,
            'type' => $parent->type,
            'cashflow_direction' => $parent->cashflow_direction,
        ]);
    }
}
