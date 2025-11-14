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
        $defaultCategories = self::getDefaultCategories();

        foreach ($defaultCategories as $category) {
            $user->categories()->create($category);
        }
    }

    /**
     * Get the default categories configuration.
     *
     * @return array<int, array{name: string, icon: string, color: string}>
     */
    public static function getDefaultCategories(): array
    {
        return [
            ['name' => 'Administrative violations', 'icon' => 'AlertTriangle', 'color' => 'red'],
            ['name' => 'Alimony and child support', 'icon' => 'Users', 'color' => 'teal'],
            ['name' => 'Association membership fees', 'icon' => 'Users2', 'color' => 'cyan'],
            ['name' => 'Bailiffs', 'icon' => 'Scale', 'color' => 'amber'],
            ['name' => 'Bills', 'icon' => 'FileText', 'color' => 'red'],
            ['name' => 'Cash deposit', 'icon' => 'Banknote', 'color' => 'green'],
            ['name' => 'Cash withdrawal', 'icon' => 'Banknote', 'color' => 'emerald'],
            ['name' => 'Cashback rewards', 'icon' => 'Gift', 'color' => 'pink'],
            ['name' => 'Cheque', 'icon' => 'Receipt', 'color' => 'gray'],
            ['name' => 'Cheque deposit', 'icon' => 'Receipt', 'color' => 'green'],
            ['name' => 'Children', 'icon' => 'Baby', 'color' => 'yellow'],
            ['name' => 'Clothing and shoes', 'icon' => 'ShoppingBag', 'color' => 'pink'],
            ['name' => 'Currency exchange', 'icon' => 'Repeat', 'color' => 'green'],
            ['name' => 'Debt collection', 'icon' => 'FileText', 'color' => 'orange'],
            ['name' => 'Dishonors', 'icon' => 'AlertCircle', 'color' => 'red'],
            ['name' => 'Dividends Income', 'icon' => 'TrendingUp', 'color' => 'emerald'],
            ['name' => 'Education, health and beauty', 'icon' => 'Heart', 'color' => 'red'],
            ['name' => 'Enquiries', 'icon' => 'HelpCircle', 'color' => 'blue'],
            ['name' => 'Entertainment', 'icon' => 'Heart', 'color' => 'purple'],
            ['name' => 'Financial services and commission', 'icon' => 'Building2', 'color' => 'slate'],
            ['name' => 'Food', 'icon' => 'Utensils', 'color' => 'orange'],
            ['name' => 'Gambling', 'icon' => 'Dices', 'color' => 'red'],
            ['name' => 'Groceries', 'icon' => 'ShoppingBag', 'color' => 'green'],
            ['name' => 'Healthcare', 'icon' => 'Heart', 'color' => 'red'],
            ['name' => 'Household goods', 'icon' => 'Home', 'color' => 'green'],
            ['name' => 'Income from rent', 'icon' => 'Building', 'color' => 'indigo'],
            ['name' => 'Insurance', 'icon' => 'Shield', 'color' => 'cyan'],
            ['name' => 'Insurance indemnity', 'icon' => 'ShieldCheck', 'color' => 'cyan'],
            ['name' => 'Interest received', 'icon' => 'TrendingUp', 'color' => 'emerald'],
            ['name' => 'Invoices', 'icon' => 'FileText', 'color' => 'indigo'],
            ['name' => 'Leisure activities, traveling', 'icon' => 'Plane', 'color' => 'purple'],
            ['name' => 'Loans', 'icon' => 'Landmark', 'color' => 'gray'],
            ['name' => 'Online transactions', 'icon' => 'CreditCard', 'color' => 'indigo'],
            ['name' => 'Other incoming payments', 'icon' => 'ArrowUpCircle', 'color' => 'green'],
            ['name' => 'Other incoming payments from employer', 'icon' => 'Briefcase', 'color' => 'blue'],
            ['name' => 'Other outgoing payments', 'icon' => 'ArrowDownCircle', 'color' => 'gray'],
            ['name' => 'Pension', 'icon' => 'Wallet', 'color' => 'teal'],
            ['name' => 'Personal transfers', 'icon' => 'ArrowLeftRight', 'color' => 'purple'],
            ['name' => 'Postal services', 'icon' => 'Mail', 'color' => 'blue'],
            ['name' => 'Regular income', 'icon' => 'TrendingUp', 'color' => 'emerald'],
            ['name' => 'Restaurants', 'icon' => 'Utensils', 'color' => 'orange'],
            ['name' => 'Return debit', 'icon' => 'Undo2', 'color' => 'amber'],
            ['name' => 'Returned payments', 'icon' => 'RotateCcw', 'color' => 'orange'],
            ['name' => 'Salary', 'icon' => 'Wallet', 'color' => 'green'],
            ['name' => 'Savings and investments', 'icon' => 'PiggyBank', 'color' => 'green'],
            ['name' => 'Scholarships', 'icon' => 'GraduationCap', 'color' => 'purple'],
            ['name' => 'Shopping', 'icon' => 'ShoppingBag', 'color' => 'pink'],
            ['name' => 'Social security benefits', 'icon' => 'HandHeart', 'color' => 'cyan'],
            ['name' => 'State aid payments', 'icon' => 'Landmark', 'color' => 'blue'],
            ['name' => 'Tax return', 'icon' => 'ReceiptText', 'color' => 'green'],
            ['name' => 'Taxes and government fees', 'icon' => 'Receipt', 'color' => 'slate'],
            ['name' => 'Transportation', 'icon' => 'Car', 'color' => 'blue'],
            ['name' => 'Travel', 'icon' => 'Plane', 'color' => 'blue'],
            ['name' => 'Utilities', 'icon' => 'Home', 'color' => 'yellow'],
            ['name' => 'Utility services', 'icon' => 'Home', 'color' => 'blue'],
            ['name' => 'Work on demand', 'icon' => 'Clock', 'color' => 'orange'],
        ];
    }
}
