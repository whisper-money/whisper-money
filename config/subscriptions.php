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
    | Supported billing_period values: 'month', 'year', null (for lifetime)
    |
    */

    'plans' => [
        'monthly' => [
            'name' => 'Pro Monthly',
            'price' => 9,
            'original_price' => null,
            'stripe_price_id' => env('STRIPE_PRO_MONTHLY_PRICE_ID', 'price_1SbJYkLNIsVExnyvAJhUoSeB'),
            'billing_period' => 'month',
            'features' => [
                'Unlimited accounts',
                'Unlimited transactions',
                'End-to-end encryption',
                'Smart categorization',
                'Automation rules',
                'Visual insights & reports',
                'Priority support',
            ],
        ],
        'yearly' => [
            'name' => 'Pro Yearly',
            'price' => 48,
            'original_price' => 144,
            'stripe_price_id' => env('STRIPE_PRO_YEARLY_PRICE_ID'),
            'billing_period' => 'year',
            'features' => [
                'Unlimited accounts',
                'Unlimited transactions',
                'End-to-end encryption',
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
        //         'End-to-end encryption',
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

    'default_plan' => 'monthly',

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

    'promo' => [
        'enabled' => env('PROMO_ENABLED', true),
        'code' => 'FOUNDER',
        'description' => '$8 off your first month',
        'badge' => 'Founder Promotion',
    ],

];
