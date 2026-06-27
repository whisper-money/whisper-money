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
    | Trial / Pricing Experiment
    |--------------------------------------------------------------------------
    |
    | A/B/C test on how the paid plan is offered. Users who register on or after
    | `started_at` are split evenly into three variants (control / reduced_trial
    | / pay_now); everyone who registered earlier stays "legacy" and keeps the
    | current trial. While `started_at` is null the experiment is off and every
    | user behaves like the control group, so this is a no-op until activated.
    |
    | - control:       the current trial (plans.*.trial_days, 15 days).
    | - reduced_trial: a shorter trial (reduced_trial.* below).
    | - pay_now:       no trial, charged immediately, with a self-service refund
    |                  window of `pay_now_refund_window_days` days.
    |
    */

    'experiment' => [
        'started_at' => env('SUBSCRIPTION_EXPERIMENT_STARTED_AT'),
        // Once a winner is chosen, set this to control / reduced_trial / pay_now
        // to give every user that variant and end the split (env-only, no deploy).
        'force_variant' => env('SUBSCRIPTION_EXPERIMENT_FORCE_VARIANT'),
        'reduced_trial' => [
            'monthly' => (int) env('SUBSCRIPTION_EXPERIMENT_REDUCED_TRIAL_MONTHLY', 3),
            'yearly' => (int) env('SUBSCRIPTION_EXPERIMENT_REDUCED_TRIAL_YEARLY', 7),
        ],
        'pay_now_refund_window_days' => (int) env('SUBSCRIPTION_EXPERIMENT_REFUND_WINDOW_DAYS', 3),
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
    */

    'plans' => [
        'monthly' => [
            'name' => 'Standard Monthly',
            'price' => 3.99,
            'original_price' => null,
            'stripe_lookup_key' => env('STRIPE_PRO_MONTHLY_LOOKUP_KEY', 'whisper_pro_monthly'),
            'billing_period' => 'month',
            'trial_days' => (int) env('STRIPE_PRO_MONTHLY_TRIAL_DAYS', 15),
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
