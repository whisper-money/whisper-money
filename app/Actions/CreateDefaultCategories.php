<?php

namespace App\Actions;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;

class CreateDefaultCategories
{
    /**
     * Create default categories for a newly registered user.
     */
    public function handle(User $user): void
    {
        $locale = $user->locale ?? app()->getLocale();
        $defaultCategories = self::getDefaultCategories($locale);

        $existingCategoryNames = $user->categories()
            ->whereIn('name', array_column($defaultCategories, 'name'))
            ->pluck('name')
            ->all();

        $now = now();
        $categories = collect($defaultCategories)
            ->reject(fn (array $category): bool => in_array($category['name'], $existingCategoryNames, true))
            ->map(fn (array $category): array => [
                ...$category,
                'cashflow_direction' => $category['cashflow_direction'] ?? CategoryCashflowDirection::Hidden->value,
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($categories === []) {
            return;
        }

        Category::query()->insert($categories);
    }

    /**
     * Get the default categories configuration for a given locale.
     *
     * @return array<int, array{name: string, icon: string, color: string, type: string, cashflow_direction?: string}>
     */
    public static function getDefaultCategories(string $locale = 'en'): array
    {
        $categories = self::getBaseCategories();

        if ($locale === 'es') {
            $translations = self::getSpanishTranslations();

            return array_map(function (array $category) use ($translations) {
                $category['name'] = $translations[$category['name']] ?? $category['name'];

                return $category;
            }, $categories);
        }

        return $categories;
    }

    /**
     * Get the base (English) categories configuration.
     *
     * @return array<int, array{name: string, icon: string, color: string, type: string, cashflow_direction?: string}>
     */
    private static function getBaseCategories(): array
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
                'type' => CategoryType::Investment->value,
            ],
            [
                'name' => 'Savings',
                'icon' => 'PiggyBank',
                'color' => 'lime',
                'type' => CategoryType::Savings->value,
            ],
            [
                'name' => 'Other investments',
                'icon' => 'TrendingUp',
                'color' => 'lime',
                'type' => CategoryType::Investment->value,
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
                'cashflow_direction' => CategoryCashflowDirection::Inflow->value,
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
                'name' => 'Self-Employment Income',
                'icon' => 'Briefcase',
                'color' => 'green',
                'type' => 'income',
            ],
            [
                'name' => 'Other incoming payments',
                'icon' => 'DollarSign',
                'color' => 'green',
                'type' => 'income',
            ],
        ];
    }

    /**
     * Get the Spanish translations for category names.
     *
     * @return array<string, string>
     */
    private static function getSpanishTranslations(): array
    {
        return [
            'Food' => 'Alimentación',
            'Cafes, restaurants, bars' => 'Cafeterías, restaurantes, bares',
            'Groceries' => 'Supermercado',
            'Tobacco and alcohol' => 'Tabaco y alcohol',
            'Other groceries' => 'Otras compras de alimentación',
            'Food delivery' => 'Comida a domicilio',
            'Utility services' => 'Servicios del hogar',
            'Electricity' => 'Electricidad',
            'Natural gas' => 'Gas natural',
            'Rent and maintanence' => 'Alquiler y mantenimiento',
            'Telephone, internet, TV, computer' => 'Teléfono, internet, TV, ordenador',
            'Water' => 'Agua',
            'Other utility expenses' => 'Otros gastos del hogar',
            'Household goods' => 'Artículos del hogar',
            'Transportation' => 'Transporte',
            'Parking' => 'Aparcamiento',
            'Fuel' => 'Combustible',
            'Transportation expenses' => 'Gastos de transporte',
            'Vehicle purchase, maintenance' => 'Compra y mantenimiento de vehículo',
            'Clothing and shoes' => 'Ropa y calzado',
            'Leisure activities, traveling' => 'Ocio y viajes',
            'Gifts' => 'Regalos',
            'Books, newspapers, magazines' => 'Libros, periódicos, revistas',
            'Accommodation, travel expenses' => 'Alojamiento y gastos de viaje',
            'Sport and sports goods' => 'Deporte y artículos deportivos',
            'Theatre, music, cinema' => 'Teatro, música, cine',
            'Hobbies and other leisure time activites' => 'Hobbies y otras actividades de ocio',
            'Education, health and beauty' => 'Educación, salud y belleza',
            'Education and courses' => 'Educación y cursos',
            'Beauty, cosmetics' => 'Belleza y cosmética',
            'Health and pharmaceuticals' => 'Salud y farmacia',
            'Online transactions' => 'Transacciones en línea',
            'Online services' => 'Servicios en línea',
            'Insurance' => 'Seguros',
            'Investments' => 'Inversiones',
            'Savings' => 'Ahorros',
            'Other investments' => 'Otras inversiones',
            'Financial services and commission' => 'Servicios financieros y comisiones',
            'Fines' => 'Multas',
            'Mortgage' => 'Hipoteca',
            'Credit card repayment' => 'Pago de tarjeta de crédito',
            'Cash withdrawal' => 'Retiro de efectivo',
            'Gambling' => 'Apuestas',
            'Lottery' => 'Lotería',
            'Taxes and government fees' => 'Impuestos y tasas',
            'Invoices' => 'Facturas',
            'Personal transfers' => 'Transferencias personales',
            'Other personal transfers' => 'Otras transferencias personales',
            'Administrative violations' => 'Infracciones administrativas',
            'Other transfers' => 'Otras transferencias',
            'Other payments' => 'Otros pagos',
            'Salary' => 'Salario',
            'Regular income' => 'Ingresos regulares',
            'Work on demand' => 'Trabajo por encargo',
            'Income from rent' => 'Ingresos por alquiler',
            'Unemployment benefit' => 'Prestación por desempleo',
            'Tax return' => 'Devolución de impuestos',
            'Return debit' => 'Devolución de débito',
            'Own account' => 'Cuenta propia',
            'From account of relatives' => 'Desde cuenta de familiares',
            'Returned payments' => 'Pagos devueltos',
            'Credit cards' => 'Tarjetas de crédito',
            'Self-Employment Income' => 'Ingresos por trabajo autónomo',
            'Other incoming payments' => 'Otros ingresos',
        ];
    }
}
