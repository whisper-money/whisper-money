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
            $user->categories()->firstOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }

    /**
     * Get the default categories configuration.
     *
     * @return array<int, array{name: string, icon: string, color: string, type: string}>
     */
    public static function getDefaultCategories(): array
    {
        return [
            [
                'name' => 'Food',
                'icon' => 'Utensils',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Cafes, restaurants, bars',
                'icon' => 'Wine',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Groceries',
                'icon' => 'ShoppingBasket',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Tobacco and alcohol',
                'icon' => 'Cigarette',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Other groceries',
                'icon' => 'ShoppingBasket',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Food delivery',
                'icon' => 'Pizza',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Utility services',
                'icon' => 'Home',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Electricity',
                'icon' => 'Bolt',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Natural gas',
                'icon' => 'Flame',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Rent and maintanence',
                'icon' => 'Wrench',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Telephone, internet, TV, computer',
                'icon' => 'Wifi',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Water',
                'icon' => 'Droplets',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Other utility expenses',
                'icon' => 'Receipt',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Household goods',
                'icon' => 'Home',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Transportation',
                'icon' => 'Bus',
                'color' => 'amber',
                'type' => 'expense',
            ],
            [
                'name' => 'Parking',
                'icon' => 'ParkingMeter',
                'color' => 'amber',
                'type' => 'expense',
            ],
            [
                'name' => 'Fuel',
                'icon' => 'Fuel',
                'color' => 'amber',
                'type' => 'expense',
            ],
            [
                'name' => 'Transportation expenses',
                'icon' => 'Ticket',
                'color' => 'amber',
                'type' => 'expense',
            ],
            [
                'name' => 'Vehicle purchase, maintenance',
                'icon' => 'Car',
                'color' => 'amber',
                'type' => 'expense',
            ],
            [
                'name' => 'Clothing and shoes',
                'icon' => 'Shirt',
                'color' => 'pink',
                'type' => 'expense',
            ],
            [
                'name' => 'Leisure activities, traveling',
                'icon' => 'Plane',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Gifts',
                'icon' => 'Gift',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Books, newspapers, magazines',
                'icon' => 'BookOpen',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Accommodation, travel expenses',
                'icon' => 'Hotel',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Sport and sports goods',
                'icon' => 'Dumbbell',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Theatre, music, cinema',
                'icon' => 'Clapperboard',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Hobbies and other leisure time activites',
                'icon' => 'Puzzle',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Education, health and beauty',
                'icon' => 'GraduationCap',
                'color' => 'rose',
                'type' => 'expense',
            ],
            [
                'name' => 'Education and courses',
                'icon' => 'GraduationCap',
                'color' => 'rose',
                'type' => 'expense',
            ],
            [
                'name' => 'Beauty, cosmetics',
                'icon' => 'Sparkles',
                'color' => 'rose',
                'type' => 'expense',
            ],
            [
                'name' => 'Health and pharmaceuticals',
                'icon' => 'HeartPulse',
                'color' => 'rose',
                'type' => 'expense',
            ],
            [
                'name' => 'Online transactions',
                'icon' => 'Globe',
                'color' => 'fuchsia',
                'type' => 'expense',
            ],
            [
                'name' => 'Online services',
                'icon' => 'Server',
                'color' => 'fuchsia',
                'type' => 'expense',
            ],
            [
                'name' => 'Insurance',
                'icon' => 'ShieldCheck',
                'color' => 'yellow',
                'type' => 'expense',
            ],
            [
                'name' => 'Investments',
                'icon' => 'LineChart',
                'color' => 'lime',
                'type' => 'expense',
            ],
            [
                'name' => 'Savings',
                'icon' => 'PiggyBank',
                'color' => 'lime',
                'type' => 'expense',
            ],
            [
                'name' => 'Other investments',
                'icon' => 'TrendingUp',
                'color' => 'lime',
                'type' => 'expense',
            ],
            [
                'name' => 'Financial services and commission',
                'icon' => 'Landmark',
                'color' => 'slate',
                'type' => 'expense',
            ],
            [
                'name' => 'Fines',
                'icon' => 'Gavel',
                'color' => 'slate',
                'type' => 'expense',
            ],
            [
                'name' => 'Mortgage',
                'icon' => 'Building2',
                'color' => 'slate',
                'type' => 'expense',
            ],
            [
                'name' => 'Credit card repayment',
                'icon' => 'CreditCard',
                'color' => 'slate',
                'type' => 'expense',
            ],
            [
                'name' => 'Cash withdrawal',
                'icon' => 'Banknote',
                'color' => 'neutral',
                'type' => 'expense',
            ],
            [
                'name' => 'Gambling',
                'icon' => 'Dice5',
                'color' => 'purple',
                'type' => 'expense',
            ],
            [
                'name' => 'Lottery',
                'icon' => 'TicketPercent',
                'color' => 'purple',
                'type' => 'expense',
            ],
            [
                'name' => 'Taxes and government fees',
                'icon' => 'FileText',
                'color' => 'stone',
                'type' => 'expense',
            ],
            [
                'name' => 'Invoices',
                'icon' => 'FileInvoice',
                'color' => 'stone',
                'type' => 'expense',
            ],
            [
                'name' => 'Personal transfers',
                'icon' => 'ArrowLeftRight',
                'color' => 'cyan',
                'type' => 'transfer',
            ],
            [
                'name' => 'Other personal transfers',
                'icon' => 'ArrowLeftRight',
                'color' => 'cyan',
                'type' => 'transfer',
            ],
            [
                'name' => 'Administrative violations',
                'icon' => 'BadgeAlert',
                'color' => 'stone',
                'type' => 'expense',
            ],
            [
                'name' => 'Other transfers',
                'icon' => 'Split',
                'color' => 'stone',
                'type' => 'transfer',
            ],
            [
                'name' => 'Other payments',
                'icon' => 'Wallet',
                'color' => 'stone',
                'type' => 'expense',
            ],
            [
                'name' => 'Salary',
                'icon' => 'Coins',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Regular income',
                'icon' => 'Coins',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Work on demand',
                'icon' => 'Briefcase',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Income from rent',
                'icon' => 'Building',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Unemployment benefit',
                'icon' => 'HandCoins',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Tax return',
                'icon' => 'RotateCcw',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Return debit',
                'icon' => 'Undo2',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Own account',
                'icon' => 'ArrowRightLeft',
                'color' => 'blue',
                'type' => 'transfer',
            ],
            [
                'name' => 'From account of relatives',
                'icon' => 'Users',
                'color' => 'blue',
                'type' => 'transfer',
            ],
            [
                'name' => 'Returned payments',
                'icon' => 'RotateCw',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Credit cards',
                'icon' => 'CreditCard',
                'color' => 'green',
                'type' => 'expense',
            ],
            [
                'name' => 'Other incoming payments',
                'icon' => 'DollarSign',
                'color' => 'green',
                'type' => 'income',
            ],
        ];
    }
}
