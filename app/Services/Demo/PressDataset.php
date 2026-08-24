<?php

namespace App\Services\Demo;

use App\Enums\AccountType;

/**
 * The seed data behind the press account: a plausible Spanish financial life, so
 * a journalist asking "¿en qué me gasté el dinero en julio?" or "recategoriza
 * los gastos de Amazon como Hogar" gets an interesting answer on the first try.
 *
 * Data only — the seeding itself lives in ResetDemoAccountCommand, which reads
 * the same array shape for the demo account. Category names are canonical
 * English and are resolved to the account's locale while seeding (see
 * CreateDefaultCategories::localizedCategoryName), so this file never has to
 * repeat the translation table.
 *
 * Amounts are in cents. The person is invented; nothing here belongs to anyone.
 */
class PressDataset
{
    private const NAME = 'Marta Ruiz Ferrer';

    private const LOCALE = 'es';

    private const CURRENCY = 'EUR';

    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        return [
            'name' => self::NAME,
            'locale' => self::LOCALE,
            'currency' => self::CURRENCY,
            'subscription_prefix' => 'sub_press_',
            'accounts' => self::accounts(),
            'labels' => self::labels(),
            'rules' => self::rules(),
            'budgets' => self::budgets(),
            'transaction_templates' => self::transactionTemplates(),
        ];
    }

    /**
     * A nómina account, a shared one, savings, a card, two investment accounts,
     * the flat and the mortgage against it — enough for net worth to mean
     * something and for the mortgage to show up as a liability.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function accounts(): array
    {
        return [
            [
                'name' => 'Cuenta Nómina',
                'type' => AccountType::Checking,
                'bank' => 'BBVA',
                'balance_min' => 280000,
                'balance_max' => 420000,
                'monthly_variance' => 90000,
            ],
            [
                'name' => 'Cuenta Compartida',
                'type' => AccountType::Checking,
                'bank' => 'BBVA',
                'balance_min' => 90000,
                'balance_max' => 180000,
                'monthly_variance' => 45000,
            ],
            [
                'name' => 'Ahorro Colchón',
                'type' => AccountType::Savings,
                'bank' => 'ING Direct',
                'balance_min' => 1200000,
                'balance_max' => 1650000,
                'monthly_variance' => 40000,
            ],
            [
                'name' => 'Tarjeta Visa BBVA',
                'type' => AccountType::CreditCard,
                'bank' => 'BBVA',
                'balance_min' => 30000,
                'balance_max' => 65000,
                'monthly_variance' => 20000,
            ],
            [
                'name' => 'Fondos Indexa',
                'type' => AccountType::Investment,
                'bank' => 'Indexa Capital',
                'balance_min' => 1600000,
                'balance_max' => 2100000,
                'monthly_variance' => 120000,
            ],
            [
                'name' => 'Cartera Binance',
                'type' => AccountType::Investment,
                'bank' => 'Binance',
                'balance_min' => 180000,
                'balance_max' => 320000,
                'monthly_variance' => 60000,
            ],
            [
                'name' => 'Piso en Carabanchel',
                'type' => AccountType::RealEstate,
                'bank' => null,
                'balance_min' => 24500000,
                'balance_max' => 24500000,
                'monthly_variance' => 0,
                'real_estate' => [
                    'property_type' => 'residential',
                    'address' => 'Calle de Antonio López, Madrid',
                    'purchase_price' => 21000000,
                    'purchase_years_ago' => 6,
                    'area_value' => 78,
                    'area_unit' => 'sqm',
                    'revaluation_percentage' => 2.5,
                ],
            ],
            [
                'name' => 'Hipoteca Piso Carabanchel',
                'type' => AccountType::Loan,
                'bank' => 'BBVA',
                'balance_min' => 16800000,
                'balance_max' => 16800000,
                'monthly_variance' => 0,
                'loan' => [
                    'original_amount' => 18900000,
                    'annual_interest_rate' => 2.65,
                    'loan_term_months' => 360,
                    'start_years_ago' => 6,
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, color: string, assignment_percentage: int}>
     */
    private static function labels(): array
    {
        return [
            ['name' => 'Imprescindible', 'color' => 'green', 'assignment_percentage' => 40],
            ['name' => 'Recurrente', 'color' => 'blue', 'assignment_percentage' => 25],
            ['name' => 'Revisar', 'color' => 'amber', 'assignment_percentage' => 15],
            ['name' => 'Compartido con Javi', 'color' => 'purple', 'assignment_percentage' => 12],
        ];
    }

    /**
     * @return array<int, array{title: string, priority: int, rules_json: array<string, mixed>, category_name: string|null, action_note: string|null}>
     */
    private static function rules(): array
    {
        return [
            [
                'title' => 'Nómina mensual',
                'priority' => 5,
                'rules_json' => ['and' => [
                    ['>' => [['var' => 'amount'], 0]],
                    ['in' => ['nomina', ['var' => 'description']]],
                ]],
                'category_name' => 'Salary',
                'action_note' => null,
            ],
            [
                'title' => 'Compra semanal',
                'priority' => 10,
                'rules_json' => ['or' => [
                    ['in' => ['mercadona', ['var' => 'description']]],
                    ['in' => ['carrefour', ['var' => 'description']]],
                    ['in' => ['lidl', ['var' => 'description']]],
                    ['in' => ['alcampo', ['var' => 'description']]],
                ]],
                'category_name' => 'Groceries',
                'action_note' => null,
            ],
            [
                'title' => 'Gasolineras',
                'priority' => 20,
                'rules_json' => ['or' => [
                    ['in' => ['repsol', ['var' => 'description']]],
                    ['in' => ['cepsa', ['var' => 'description']]],
                    ['in' => ['galp', ['var' => 'description']]],
                ]],
                'category_name' => 'Fuel',
                'action_note' => null,
            ],
            [
                'title' => 'Suscripciones online',
                'priority' => 30,
                'rules_json' => ['or' => [
                    ['in' => ['netflix', ['var' => 'description']]],
                    ['in' => ['spotify', ['var' => 'description']]],
                    ['in' => ['hbo', ['var' => 'description']]],
                    ['in' => ['filmin', ['var' => 'description']]],
                ]],
                'category_name' => 'Online services',
                'action_note' => null,
            ],
            [
                'title' => 'Transporte urbano',
                'priority' => 40,
                'rules_json' => ['or' => [
                    ['in' => ['cabify', ['var' => 'description']]],
                    ['in' => ['uber', ['var' => 'description']]],
                    ['in' => ['metro de madrid', ['var' => 'description']]],
                    ['in' => ['renfe', ['var' => 'description']]],
                ]],
                'category_name' => 'Transportation expenses',
                'action_note' => 'Revisar si compensa el abono transporte',
            ],
            [
                'title' => 'Bizum a amigos',
                'priority' => 50,
                'rules_json' => ['in' => ['bizum', ['var' => 'description']]],
                'category_name' => 'Other personal transfers',
                'action_note' => null,
            ],
        ];
    }

    /**
     * Budgets whose current period is already under way, so the budget questions
     * have progress to report rather than an empty shell.
     *
     * @return array<int, array{name: string, category_name: string, period_type: string, period_start_day: int, rollover_type: string, allocated_amount: int}>
     */
    private static function budgets(): array
    {
        return [
            [
                'name' => 'Compra del mes',
                'category_name' => 'Groceries',
                'period_type' => 'monthly',
                'period_start_day' => 1,
                'rollover_type' => 'carry_over',
                'allocated_amount' => 45000,
            ],
            [
                'name' => 'Bares y restaurantes',
                'category_name' => 'Cafes, restaurants, bars',
                'period_type' => 'weekly',
                'period_start_day' => 1,
                'rollover_type' => 'reset',
                'allocated_amount' => 6000,
            ],
            [
                'name' => 'Ocio y cultura',
                'category_name' => 'Theatre, music, cinema',
                'period_type' => 'monthly',
                'period_start_day' => 1,
                'rollover_type' => 'reset',
                'allocated_amount' => 12000,
            ],
        ];
    }

    /**
     * Real Spanish merchants, at the frequency and size they actually hit a
     * current account. `frequency` is interpreted by DemoTransactionsProvider.
     *
     * @return array<int, array{description: string, amount_min: int, amount_max: int, category_name: string, frequency: string}>
     */
    private static function transactionTemplates(): array
    {
        return [
            // Income.
            ['description' => 'Nómina Indra Sistemas SA', 'amount_min' => 352000, 'amount_max' => 352000, 'category_name' => 'Salary', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Paga extra Indra Sistemas SA', 'amount_min' => 300000, 'amount_max' => 320000, 'category_name' => 'Salary', 'frequency' => 'biannual', 'account' => 'Cuenta Nómina'],
            ['description' => 'Devolución IRPF Agencia Tributaria', 'amount_min' => 42000, 'amount_max' => 78000, 'category_name' => 'Tax return', 'frequency' => 'yearly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Bizum de Javi (cena)', 'amount_min' => 1200, 'amount_max' => 3500, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Bizum de Laura (regalo Rocío)', 'amount_min' => 1500, 'amount_max' => 2500, 'category_name' => 'Other personal transfers', 'frequency' => 'quarterly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Reembolso Amazon.es', 'amount_min' => 1900, 'amount_max' => 8900, 'category_name' => 'Returned payments', 'frequency' => 'quarterly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Dividendo Iberdrola', 'amount_min' => 3200, 'amount_max' => 6800, 'category_name' => 'Other incoming payments', 'frequency' => 'biannual', 'account' => 'Cuenta Nómina'],

            // Housing.
            ['description' => 'Hipoteca BBVA', 'amount_min' => -78500, 'amount_max' => -78500, 'category_name' => 'Mortgage', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Comunidad de propietarios', 'amount_min' => -6500, 'amount_max' => -6500, 'category_name' => 'Rent and maintanence', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Iberdrola Clientes', 'amount_min' => -9800, 'amount_max' => -14500, 'category_name' => 'Electricity', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Naturgy Iberia', 'amount_min' => -3200, 'amount_max' => -8900, 'category_name' => 'Natural gas', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Canal de Isabel II', 'amount_min' => -2800, 'amount_max' => -4500, 'category_name' => 'Water', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Movistar Fusión', 'amount_min' => -6999, 'amount_max' => -6999, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Vodafone España', 'amount_min' => -1599, 'amount_max' => -1599, 'category_name' => 'Telephone, internet, TV, computer', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Seguro hogar Mapfre', 'amount_min' => -2450, 'amount_max' => -2450, 'category_name' => 'Insurance', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Seguro coche Línea Directa', 'amount_min' => -3890, 'amount_max' => -3890, 'category_name' => 'Insurance', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Adeslas seguro salud', 'amount_min' => -5600, 'amount_max' => -5600, 'category_name' => 'Insurance', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'IBI Ayuntamiento de Madrid', 'amount_min' => -32000, 'amount_max' => -32000, 'category_name' => 'Taxes and government fees', 'frequency' => 'yearly', 'account' => 'Cuenta Nómina'],

            // Groceries.
            ['description' => 'Mercadona', 'amount_min' => -7800, 'amount_max' => -4200, 'category_name' => 'Groceries', 'frequency' => 'weekly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Mercadona Antonio López', 'amount_min' => -3000, 'amount_max' => -1400, 'category_name' => 'Groceries', 'frequency' => 'frequent', 'account' => 'Cuenta Compartida'],
            ['description' => 'Carrefour Market', 'amount_min' => -6800, 'amount_max' => -2900, 'category_name' => 'Groceries', 'frequency' => 'biweekly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Lidl', 'amount_min' => -4500, 'amount_max' => -2200, 'category_name' => 'Groceries', 'frequency' => 'biweekly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Alcampo', 'amount_min' => -11500, 'amount_max' => -6500, 'category_name' => 'Groceries', 'frequency' => 'quarterly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Frutería La Huerta', 'amount_min' => -1800, 'amount_max' => -900, 'category_name' => 'Groceries', 'frequency' => 'weekly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Panadería Viena La Baguette', 'amount_min' => -650, 'amount_max' => -280, 'category_name' => 'Groceries', 'frequency' => 'frequent', 'account' => 'Cuenta Compartida'],
            ['description' => 'Carnicería Hermanos Gómez', 'amount_min' => -2400, 'amount_max' => -1200, 'category_name' => 'Groceries', 'frequency' => 'biweekly', 'account' => 'Cuenta Compartida'],

            // Eating out.
            ['description' => 'Bar Manolo', 'amount_min' => -1400, 'amount_max' => -450, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'frequent', 'account' => 'Cuenta Compartida'],
            ['description' => 'Cafetería La Rosa', 'amount_min' => -520, 'amount_max' => -180, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'frequent', 'account' => 'Cuenta Compartida'],
            ['description' => 'Cervecería 100 Montaditos', 'amount_min' => -2200, 'amount_max' => -900, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'biweekly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Restaurante Casa Paco', 'amount_min' => -6800, 'amount_max' => -3200, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Telepizza', 'amount_min' => -2100, 'amount_max' => -1400, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'biweekly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Taberna El Sur', 'amount_min' => -4500, 'amount_max' => -2200, 'category_name' => 'Cafes, restaurants, bars', 'frequency' => 'monthly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Glovo', 'amount_min' => -2800, 'amount_max' => -1500, 'category_name' => 'Food delivery', 'frequency' => 'biweekly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Just Eat', 'amount_min' => -2500, 'amount_max' => -1300, 'category_name' => 'Food delivery', 'frequency' => 'biweekly', 'account' => 'Cuenta Compartida'],
            ['description' => 'Uber Eats', 'amount_min' => -2600, 'amount_max' => -1400, 'category_name' => 'Food delivery', 'frequency' => 'biweekly', 'account' => 'Cuenta Compartida'],

            // Transport.
            ['description' => 'Repsol', 'amount_min' => -6800, 'amount_max' => -4200, 'category_name' => 'Fuel', 'frequency' => 'biweekly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Cepsa', 'amount_min' => -6200, 'amount_max' => -3800, 'category_name' => 'Fuel', 'frequency' => 'biweekly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Galp Energía', 'amount_min' => -5900, 'amount_max' => -3600, 'category_name' => 'Fuel', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Abono transporte Metro de Madrid', 'amount_min' => -5460, 'amount_max' => -5460, 'category_name' => 'Transportation expenses', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Renfe Cercanías', 'amount_min' => -1800, 'amount_max' => -560, 'category_name' => 'Transportation expenses', 'frequency' => 'biweekly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Cabify', 'amount_min' => -1900, 'amount_max' => -780, 'category_name' => 'Transportation expenses', 'frequency' => 'biweekly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Uber', 'amount_min' => -1700, 'amount_max' => -700, 'category_name' => 'Transportation expenses', 'frequency' => 'biweekly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Parking Plaza Mayor', 'amount_min' => -1200, 'amount_max' => -400, 'category_name' => 'Parking', 'frequency' => 'biweekly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Autopista AP-6 peaje', 'amount_min' => -980, 'amount_max' => -560, 'category_name' => 'Transportation expenses', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Taller Midas revisión', 'amount_min' => -18500, 'amount_max' => -7500, 'category_name' => 'Vehicle purchase, maintenance', 'frequency' => 'quarterly', 'account' => 'Cuenta Nómina'],
            ['description' => 'ITV Madrid', 'amount_min' => -4200, 'amount_max' => -4200, 'category_name' => 'Vehicle purchase, maintenance', 'frequency' => 'yearly', 'account' => 'Cuenta Nómina'],

            // Subscriptions.
            ['description' => 'Netflix', 'amount_min' => -1399, 'amount_max' => -1399, 'category_name' => 'Online services', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Spotify Premium Duo', 'amount_min' => -1499, 'amount_max' => -1499, 'category_name' => 'Online services', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'HBO Max', 'amount_min' => -999, 'amount_max' => -999, 'category_name' => 'Online services', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Filmin', 'amount_min' => -799, 'amount_max' => -799, 'category_name' => 'Online services', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Amazon Prime', 'amount_min' => -4990, 'amount_max' => -4990, 'category_name' => 'Online services', 'frequency' => 'yearly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'iCloud+ 200 GB', 'amount_min' => -299, 'amount_max' => -299, 'category_name' => 'Online services', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'El País suscripción digital', 'amount_min' => -999, 'amount_max' => -999, 'category_name' => 'Books, newspapers, magazines', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],

            // Shopping.
            ['description' => 'Amazon.es', 'amount_min' => -5900, 'amount_max' => -1200, 'category_name' => 'Online transactions', 'frequency' => 'weekly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'El Corte Inglés', 'amount_min' => -12500, 'amount_max' => -3500, 'category_name' => 'Clothing and shoes', 'frequency' => 'quarterly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Zara', 'amount_min' => -7900, 'amount_max' => -2990, 'category_name' => 'Clothing and shoes', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Decathlon', 'amount_min' => -6500, 'amount_max' => -1900, 'category_name' => 'Sport and sports goods', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'IKEA Alcorcón', 'amount_min' => -15900, 'amount_max' => -3500, 'category_name' => 'Household goods', 'frequency' => 'quarterly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Leroy Merlin', 'amount_min' => -9800, 'amount_max' => -2200, 'category_name' => 'Household goods', 'frequency' => 'quarterly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Primor cosmética', 'amount_min' => -3200, 'amount_max' => -1200, 'category_name' => 'Beauty, cosmetics', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Casa del Libro', 'amount_min' => -3500, 'amount_max' => -1500, 'category_name' => 'Books, newspapers, magazines', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],

            // Health, leisure, other.
            ['description' => 'Farmacia Antonio López', 'amount_min' => -2800, 'amount_max' => -650, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'biweekly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Clínica dental Vitaldent', 'amount_min' => -12000, 'amount_max' => -6000, 'category_name' => 'Health and pharmaceuticals', 'frequency' => 'quarterly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Basic-Fit Madrid', 'amount_min' => -2999, 'amount_max' => -2999, 'category_name' => 'Sport and sports goods', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Cines Yelmo Ideal', 'amount_min' => -2400, 'amount_max' => -1600, 'category_name' => 'Theatre, music, cinema', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Teatro Lara entradas', 'amount_min' => -4800, 'amount_max' => -2800, 'category_name' => 'Theatre, music, cinema', 'frequency' => 'quarterly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Peluquería Marisa', 'amount_min' => -3500, 'amount_max' => -1800, 'category_name' => 'Beauty, cosmetics', 'frequency' => 'monthly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Vueling Airlines', 'amount_min' => -18500, 'amount_max' => -6500, 'category_name' => 'Accommodation, travel expenses', 'frequency' => 'quarterly', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Booking.com Cádiz', 'amount_min' => -32000, 'amount_max' => -14000, 'category_name' => 'Accommodation, travel expenses', 'frequency' => 'biannual', 'account' => 'Tarjeta Visa BBVA'],
            ['description' => 'Bizum a Javi (cena)', 'amount_min' => -3500, 'amount_max' => -1500, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Bizum a Laura (regalo)', 'amount_min' => -3000, 'amount_max' => -2000, 'category_name' => 'Other personal transfers', 'frequency' => 'quarterly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Cuota Cruz Roja', 'amount_min' => -1000, 'amount_max' => -1000, 'category_name' => 'Other personal transfers', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Retirada efectivo cajero BBVA', 'amount_min' => -12000, 'amount_max' => -6000, 'category_name' => 'Cash withdrawal', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Aportación Indexa Capital', 'amount_min' => -30000, 'amount_max' => -30000, 'category_name' => 'Savings', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Traspaso a Ahorro Colchón', 'amount_min' => -25000, 'amount_max' => -15000, 'category_name' => 'Own account', 'frequency' => 'monthly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Multa DGT exceso velocidad', 'amount_min' => -10000, 'amount_max' => -10000, 'category_name' => 'Fines', 'frequency' => 'yearly', 'account' => 'Cuenta Nómina'],
            ['description' => 'Comisión mantenimiento BBVA', 'amount_min' => -1200, 'amount_max' => -1200, 'category_name' => 'Financial services and commission', 'frequency' => 'quarterly', 'account' => 'Cuenta Nómina'],
        ];
    }
}
