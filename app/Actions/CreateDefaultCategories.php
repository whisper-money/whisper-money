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
     * @return array<int, array{name: string, icon: string, color: string}>
     */
    public static function getDefaultCategories(): array
    {
        return [
            [
                'name' => 'Food',
                'icon' => 'Utensils',
                'color' => 'red',
            ],
            [
                'name' => 'Cafes, restaurants, bars',
                'icon' => 'Wine',
                'color' => 'red',
            ],
            [
                'name' => 'Groceries',
                'icon' => 'ShoppingBasket',
                'color' => 'red',
            ],
            [
                'name' => 'Tobacco and alcohol',
                'icon' => 'Cigarette',
                'color' => 'red',
            ],
            [
                'name' => 'Other groceries',
                'icon' => 'ShoppingBasket',
                'color' => 'red',
            ],
            [
                'name' => 'Food delivery',
                'icon' => 'Pizza',
                'color' => 'red',
            ],
            [
                'name' => 'Utility services',
                'icon' => 'Home',
                'color' => 'orange',
            ],
            [
                'name' => 'Electricity',
                'icon' => 'Bolt',
                'color' => 'orange',
            ],
            [
                'name' => 'Natural gas',
                'icon' => 'Flame',
                'color' => 'orange',
            ],
            [
                'name' => 'Rent and maintanence',
                'icon' => 'Wrench',
                'color' => 'orange',
            ],
            [
                'name' => 'Telephone, internet, TV, computer',
                'icon' => 'Wifi',
                'color' => 'orange',
            ],
            [
                'name' => 'Water',
                'icon' => 'Droplets',
                'color' => 'orange',
            ],
            [
                'name' => 'Other utility expenses',
                'icon' => 'Receipt',
                'color' => 'orange',
            ],
            [
                'name' => 'Household goods',
                'icon' => 'Home',
                'color' => 'orange',
            ],
            [
                'name' => 'Transportation',
                'icon' => 'Bus',
                'color' => 'amber',
            ],
            [
                'name' => 'Parking',
                'icon' => 'ParkingMeter',
                'color' => 'amber',
            ],
            [
                'name' => 'Fuel',
                'icon' => 'Fuel',
                'color' => 'amber',
            ],
            [
                'name' => 'Transportation expenses',
                'icon' => 'Ticket',
                'color' => 'amber',
            ],
            [
                'name' => 'Vehicle purchase, maintenance',
                'icon' => 'Car',
                'color' => 'amber',
            ],
            [
                'name' => 'Clothing and shoes',
                'icon' => 'Shirt',
                'color' => 'pink',
            ],
            [
                'name' => 'Leisure activities, traveling',
                'icon' => 'Plane',
                'color' => 'violet',
            ],
            [
                'name' => 'Gifts',
                'icon' => 'Gift',
                'color' => 'violet',
            ],
            [
                'name' => 'Books, newspapers, magazines',
                'icon' => 'BookOpen',
                'color' => 'violet',
            ],
            [
                'name' => 'Accommodation, travel expenses',
                'icon' => 'Hotel',
                'color' => 'violet',
            ],
            [
                'name' => 'Sport and sports goods',
                'icon' => 'Dumbbell',
                'color' => 'violet',
            ],
            [
                'name' => 'Theatre, music, cinema',
                'icon' => 'Clapperboard',
                'color' => 'violet',
            ],
            [
                'name' => 'Hobbies and other leisure time activites',
                'icon' => 'Puzzle',
                'color' => 'violet',
            ],
            [
                'name' => 'Education, health and beauty',
                'icon' => 'GraduationCap',
                'color' => 'rose',
            ],
            [
                'name' => 'Education and courses',
                'icon' => 'GraduationCap',
                'color' => 'rose',
            ],
            [
                'name' => 'Beauty, cosmetics',
                'icon' => 'Sparkles',
                'color' => 'rose',
            ],
            [
                'name' => 'Health and pharmaceuticals',
                'icon' => 'HeartPulse',
                'color' => 'rose',
            ],
            [
                'name' => 'Online transactions',
                'icon' => 'Globe',
                'color' => 'fuchsia',
            ],
            [
                'name' => 'Online services',
                'icon' => 'Server',
                'color' => 'fuchsia',
            ],
            [
                'name' => 'Insurance',
                'icon' => 'ShieldCheck',
                'color' => 'yellow',
            ],
            [
                'name' => 'Investments',
                'icon' => 'LineChart',
                'color' => 'lime',
            ],
            [
                'name' => 'Savings',
                'icon' => 'PiggyBank',
                'color' => 'lime',
            ],
            [
                'name' => 'Other investments',
                'icon' => 'TrendingUp',
                'color' => 'lime',
            ],
            [
                'name' => 'Financial services and commission',
                'icon' => 'Landmark',
                'color' => 'slate',
            ],
            [
                'name' => 'Fines',
                'icon' => 'Gavel',
                'color' => 'slate',
            ],
            [
                'name' => 'Mortgage',
                'icon' => 'Building2',
                'color' => 'slate',
            ],
            [
                'name' => 'Credit card repayment',
                'icon' => 'CreditCard',
                'color' => 'slate',
            ],
            [
                'name' => 'Cash withdrawal',
                'icon' => 'Banknote',
                'color' => 'neutral',
            ],
            [
                'name' => 'Gambling',
                'icon' => 'Dice5',
                'color' => 'purple',
            ],
            [
                'name' => 'Lottery',
                'icon' => 'TicketPercent',
                'color' => 'purple',
            ],
            [
                'name' => 'Taxes and government fees',
                'icon' => 'FileText',
                'color' => 'stone',
            ],
            [
                'name' => 'Invoices',
                'icon' => 'FileInvoice',
                'color' => 'stone',
            ],
            [
                'name' => 'Personal transfers',
                'icon' => 'ArrowLeftRight',
                'color' => 'cyan',
            ],
            [
                'name' => 'Other personal transfers',
                'icon' => 'ArrowLeftRight',
                'color' => 'cyan',
            ],
            [
                'name' => 'Administrative violations',
                'icon' => 'BadgeAlert',
                'color' => 'stone',
            ],
            [
                'name' => 'Other transfers',
                'icon' => 'Split',
                'color' => 'stone',
            ],
            [
                'name' => 'Other payments',
                'icon' => 'Wallet',
                'color' => 'stone',
            ],
            [
                'name' => 'Salary',
                'icon' => 'Coins',
                'color' => 'green',
            ],
            [
                'name' => 'Regular income',
                'icon' => 'Coins',
                'color' => 'green',
            ],
            [
                'name' => 'Work on demand',
                'icon' => 'Briefcase',
                'color' => 'green',
            ],
            [
                'name' => 'Income from rent',
                'icon' => 'Building',
                'color' => 'green',
            ],
            [
                'name' => 'Unemployment benefit',
                'icon' => 'HandCoins',
                'color' => 'green',
            ],
            [
                'name' => 'Tax return',
                'icon' => 'RotateCcw',
                'color' => 'green',
            ],
            [
                'name' => 'Return debit',
                'icon' => 'Undo2',
                'color' => 'green',
            ],
            [
                'name' => 'Own account',
                'icon' => 'ArrowRightLeft',
                'color' => 'blue',
            ],
            [
                'name' => 'From account of relatives',
                'icon' => 'Users',
                'color' => 'blue',
            ],
            [
                'name' => 'Returned payments',
                'icon' => 'RotateCw',
                'color' => 'green',
            ],
            [
                'name' => 'Credit cards',
                'icon' => 'CreditCard',
                'color' => 'green',
            ],
            [
                'name' => 'Other incoming payments',
                'icon' => 'DollarSign',
                'color' => 'green',
            ],
        ];
    }
}
