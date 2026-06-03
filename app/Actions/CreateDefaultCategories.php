<?php

namespace App\Actions;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class CreateDefaultCategories
{
    /**
     * Create default categories for a newly registered user, nesting child
     * categories under their configured parent.
     */
    public function handle(User $user): void
    {
        $locale = $user->locale ?? app()->getLocale();
        $defaultCategories = self::getDefaultCategories($locale);

        $existingCategories = $user->categories()
            ->whereIn('name', array_column($defaultCategories, 'name'))
            ->pluck('id', 'name');

        $now = now();
        $pending = collect($defaultCategories)
            ->reject(fn (array $category): bool => $existingCategories->has($category['name']))
            ->map(fn (array $category): array => [
                ...$category,
                'cashflow_direction' => $category['cashflow_direction'] ?? CategoryCashflowDirection::Hidden->value,
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        if ($pending->isEmpty()) {
            return;
        }

        $idsByName = $existingCategories->merge($pending->pluck('id', 'name'));

        [$children, $roots] = $pending->partition(fn (array $category): bool => isset($category['parent']));

        if ($roots->isNotEmpty()) {
            Category::query()->insert(
                $roots->map(fn (array $category): array => Arr::except($category, 'parent'))->values()->all()
            );
        }

        if ($children->isNotEmpty()) {
            Category::query()->insert(
                $children
                    ->map(fn (array $category): array => [
                        ...Arr::except($category, 'parent'),
                        'parent_id' => $idsByName[$category['parent']] ?? null,
                    ])
                    ->values()
                    ->all()
            );
        }
    }

    /**
     * Get the default categories configuration for a given locale.
     *
     * @return array<int, array{name: string, icon: string, color: string, type: string, cashflow_direction?: string, parent?: string}>
     */
    public static function getDefaultCategories(string $locale = 'en'): array
    {
        $categories = self::getBaseCategories();

        if ($locale === 'es') {
            $translations = self::getSpanishTranslations();

            return array_map(function (array $category) use ($translations) {
                $category['name'] = $translations[$category['name']] ?? $category['name'];

                if (isset($category['parent'])) {
                    $category['parent'] = $translations[$category['parent']] ?? $category['parent'];
                }

                return $category;
            }, $categories);
        }

        return $categories;
    }

    /**
     * Get the base (English) categories configuration. A `parent` entry nests
     * the category under the default category with that (English) name.
     *
     * @return array<int, array{name: string, icon: string, color: string, type: string, cashflow_direction?: string, parent?: string}>
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
                'parent' => 'Food',
                'icon' => 'Wine',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Groceries',
                'parent' => 'Food',
                'icon' => 'ShoppingBasket',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Tobacco and alcohol',
                'parent' => 'Food',
                'icon' => 'Cigarette',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Other groceries',
                'parent' => 'Food',
                'icon' => 'ShoppingBasket',
                'color' => 'red',
                'type' => 'expense',
            ],
            [
                'name' => 'Food delivery',
                'parent' => 'Food',
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
                'parent' => 'Utility services',
                'icon' => 'Bolt',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Natural gas',
                'parent' => 'Utility services',
                'icon' => 'Flame',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Rent and maintanence',
                'parent' => 'Utility services',
                'icon' => 'Wrench',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Telephone, internet, TV, computer',
                'parent' => 'Utility services',
                'icon' => 'Wifi',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Water',
                'parent' => 'Utility services',
                'icon' => 'Droplets',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Other utility expenses',
                'parent' => 'Utility services',
                'icon' => 'Receipt',
                'color' => 'orange',
                'type' => 'expense',
            ],
            [
                'name' => 'Household goods',
                'parent' => 'Utility services',
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
                'parent' => 'Transportation',
                'icon' => 'ParkingMeter',
                'color' => 'amber',
                'type' => 'expense',
            ],
            [
                'name' => 'Fuel',
                'parent' => 'Transportation',
                'icon' => 'Fuel',
                'color' => 'amber',
                'type' => 'expense',
            ],
            [
                'name' => 'Transportation expenses',
                'parent' => 'Transportation',
                'icon' => 'Ticket',
                'color' => 'amber',
                'type' => 'expense',
            ],
            [
                'name' => 'Vehicle purchase, maintenance',
                'parent' => 'Transportation',
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
                'parent' => 'Leisure activities, traveling',
                'icon' => 'Gift',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Books, newspapers, magazines',
                'parent' => 'Leisure activities, traveling',
                'icon' => 'BookOpen',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Accommodation, travel expenses',
                'parent' => 'Leisure activities, traveling',
                'icon' => 'Hotel',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Sport and sports goods',
                'parent' => 'Leisure activities, traveling',
                'icon' => 'Dumbbell',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Theatre, music, cinema',
                'parent' => 'Leisure activities, traveling',
                'icon' => 'Clapperboard',
                'color' => 'violet',
                'type' => 'expense',
            ],
            [
                'name' => 'Hobbies and other leisure time activites',
                'parent' => 'Leisure activities, traveling',
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
                'parent' => 'Education, health and beauty',
                'icon' => 'GraduationCap',
                'color' => 'rose',
                'type' => 'expense',
            ],
            [
                'name' => 'Beauty, cosmetics',
                'parent' => 'Education, health and beauty',
                'icon' => 'Sparkles',
                'color' => 'rose',
                'type' => 'expense',
            ],
            [
                'name' => 'Health and pharmaceuticals',
                'parent' => 'Education, health and beauty',
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
                'parent' => 'Online transactions',
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
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
            ],
            [
                'name' => 'Savings',
                'icon' => 'PiggyBank',
                'color' => 'lime',
                'type' => CategoryType::Savings->value,
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
            ],
            [
                'name' => 'Other investments',
                'parent' => 'Investments',
                'icon' => 'TrendingUp',
                'color' => 'lime',
                'type' => CategoryType::Investment->value,
                'cashflow_direction' => CategoryCashflowDirection::Outflow->value,
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
                'parent' => 'Gambling',
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
                'parent' => 'Personal transfers',
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
