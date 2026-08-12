<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Subscriptions Enabled
    |--------------------------------------------------------------------------
    |
    | This option controls whether the subscription system is enabled. When
    | disabled, all users will have access to all features without needing
    | to subscribe. This is useful for development or self-hosted instances.
    |
    */

    'enabled' => env('SUBSCRIPTIONS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Price Experiment
    |--------------------------------------------------------------------------
    |
    | A/B test on the price of the paid plan. Users who register on or after
    | `started_at` are split 50/50 into `control` (the plans.* prices below) and
    | `high` (the variant tier); anyone older keeps the control price. While
    | `started_at` is null the experiment is off. Set `force_variant` to
    | control/high to roll a winner out to everyone without a deploy.
    |
    | Each variant needs its own Stripe price — run `php artisan stripe:sync-prices`
    | BEFORE setting `started_at`. The yearly price is the monthly × 6, matching
    | the control plan structure.
    |
    */

    'price_experiment' => [
        'started_at' => env('PRICE_EXPERIMENT_STARTED_AT'),
        'force_variant' => env('PRICE_EXPERIMENT_FORCE_VARIANT'),
        'variants' => [
            'high' => [
                'monthly' => [
                    'price' => 8.99,
                    'original_price' => null,
                    'lookup' => 'whisper_pro_monthly_high',
                ],
                'yearly' => [
                    'price' => 53.94,
                    'original_price' => 107.88,
                    'lookup' => 'whisper_pro_yearly_high',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Product IDs
    |--------------------------------------------------------------------------
    |
    | These are the Stripe Product IDs for reference.
    |
    */

    'products' => [
        'pro' => env('STRIPE_PRO_PRODUCT_ID', 'prod_TYQPg0s9rpxNsU'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | Define all available subscription plans here. Each plan includes display
    | information (name, price, features) and Stripe configuration. The key
    | is used as the plan identifier.
    |
    | Prices are in the configured Cashier currency (see config/cashier.php).
    | Run `php artisan stripe:sync-prices` to create or update Stripe prices
    | automatically from this config. Prices are referenced by lookup key.
    |
    | Supported billing_period values: 'month', 'year', null (for lifetime)
    |
    | `trial_days` is per plan: the longer commitment gets the longer trial.
    | Set it to 0 to charge that plan immediately, with no free trial.
    |
    */

    'plans' => [
        'monthly' => [
            'name' => 'Standard Monthly',
            'price' => 3.99,
            'original_price' => null,
            'stripe_lookup_key' => env('STRIPE_PRO_MONTHLY_LOOKUP_KEY', 'whisper_pro_monthly'),
            'billing_period' => 'month',
            'trial_days' => (int) env('STRIPE_PRO_MONTHLY_TRIAL_DAYS', 7),
            'features' => [
                'Connect bank accounts',
                'AI Suggestions',
                'Unlimited accounts',
                'Unlimited transactions',
                'Your data stays yours',
                'Smart categorization',
                'Automation rules',
                'Visual insights & reports',
                'Priority support',
            ],
        ],
        'yearly' => [
            'name' => 'Standard Yearly',
            'price' => 23.88,
            'original_price' => 47.88,
            'stripe_lookup_key' => env('STRIPE_PRO_YEARLY_LOOKUP_KEY', 'whisper_pro_yearly'),
            'billing_period' => 'year',
            'trial_days' => (int) env('STRIPE_PRO_YEARLY_TRIAL_DAYS', 15),
            'features' => [
                'Connect bank accounts',
                'AI Suggestions',
                'Unlimited accounts',
                'Unlimited transactions',
                'Your data stays yours',
                'Smart categorization',
                'Automation rules',
                'Visual insights & reports',
                'Priority support',
            ],
        ],
        // 'lifetime' => [
        //     'name' => 'Lifetime License',
        //     'price' => 129,
        //     'original_price' => 299,
        //     'stripe_price_id' => env('STRIPE_LIFETIME_PRICE_ID'),
        //     'billing_period' => null,
        //     'features' => [
        //         'Unlimited accounts',
        //         'Unlimited transactions',
        //         'Your data stays yours',
        //         'Smart categorization',
        //         'Automation rules',
        //         'Visual insights & reports',
        //         'Priority support',
        //         'Lifetime updates',
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Plan
    |--------------------------------------------------------------------------
    |
    | The default plan key to display prominently or use for checkout.
    |
    */

    'default_plan' => 'yearly',

    /*
    |--------------------------------------------------------------------------
    | Best Value Plan
    |--------------------------------------------------------------------------
    |
    | The plan key that is considered the "best value" and should be.
    |
    */

    'best_value_plan' => 'yearly',

    /*
    |--------------------------------------------------------------------------
    | Promotional Code Configuration
    |--------------------------------------------------------------------------
    |
    | Configure promotional codes to display on pricing pages. Set enabled
    | to false to hide all promo code mentions from the UI.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Tax Rates
    |--------------------------------------------------------------------------
    |
    | Stripe tax rate IDs applied to every subscription created via Cashier.
    | Configure tax rates in your Stripe dashboard and reference them here.
    |
    */

    'tax_rates' => array_values(array_filter(explode(',', (string) env('STRIPE_TAX_RATES', 'txr_1TPfzrLRCmKA3oWMNWmkQeq2')))),

    'promo' => [
        'enabled' => env('PROMO_ENABLED', true),
        'code' => 'FOUNDER',
        'description' => '80% off your first period',
        'badge' => 'Founder Promotion',
    ],

];
