<?php

use App\Support\PriceTiers;

/*
|--------------------------------------------------------------------------
| Price
|--------------------------------------------------------------------------
|
| The price the app quotes and charges everybody: the default tier of
| `App\Support\PriceTiers`, which supplies the displayed price, the
| struck-through price and the Stripe lookup key as one unit, so what is shown
| is always what is charged. The regional `latam` price is offered on top of
| it (see "LATAM Pricing").
|
*/

$tier = PriceTiers::plansFor(PriceTiers::DEFAULT);

/*
| The plan preselected on every pricing surface, checked against the tier's own
| plan keys (the same keys as `plans` below) further down. See "Default Plan".
*/
$defaultPlan = strtolower(trim((string) env('SUBSCRIPTIONS_DEFAULT_PLAN', 'yearly')));

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
    | LATAM Pricing
    |--------------------------------------------------------------------------
    |
    | Whether the regional `latam` price (`App\Support\PriceTiers`) is offered
    | to users detected in Latin America (`App\Support\PricingRegion`). Off,
    | which is the default, nobody is assigned to it at signup and every
    | pricing surface quotes the default price.
    |
    | It only governs *offering* the price. What a subscriber can use always
    | follows the Stripe price they actually pay, so turning this off never
    | changes anything for someone already subscribed at the latam price, and
    | a user marked `latam` at signup is simply quoted the default price
    | again while it is off (env-only, no deploy — `php artisan config:clear`
    | after changing it).
    |
    */

    'latam_pricing' => filter_var(env('SUBSCRIPTION_LATAM_PRICING', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Second Offer
    |--------------------------------------------------------------------------
    |
    | Whether Pro is only asked for once the user has data of their own
    | (`User::asksToPayOnlyWithData()`). On, a user with no transactions is
    | not sent to the paywall nor shown the onboarding AI gate; the paywall
    | greets them once their first transactions land. And a free user who met
    | the paywall with nothing imported is offered Pro once more, in an in-app
    | dialog, after importing (`User::isEligibleForSecondOffer()`). Off, which
    | is the default, nothing changes for anyone.
    |
    */

    'second_offer' => filter_var(env('SUBSCRIPTION_SECOND_OFFER', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Free Plan Escape Delay
    |--------------------------------------------------------------------------
    |
    | How long the paywall stays shut for a user who finished onboarding with a
    | bank connected or AI switched on: until this many hours have passed the
    | only way forward is picking a plan. After that the paywall also offers to
    | drop them to the free plan, which disconnects their banks and revokes
    | their AI consent. The delay is what keeps a brand new user from being
    | invited to throw away what they just connected. Set it to 0 to offer the
    | free plan straight away.
    |
    | Dead for anyone onboarding now: a bank cannot be connected and AI cannot
    | be switched on without a subscription, so nobody finishes onboarding with
    | something to throw away and no plan to lose it from. It still governs the
    | legacy users who reached that state under the old rules, which is why it
    | stays.
    |
    */

    'free_plan_escape_delay_hours' => (int) env('SUBSCRIPTIONS_FREE_PLAN_ESCAPE_DELAY_HOURS', 3),

    /*
    |--------------------------------------------------------------------------
    | Retention Coupon
    |--------------------------------------------------------------------------
    |
    | The discount the in-app cancel flow offers to a subscriber who says the
    | plan is too expensive, once per user. `stripe:ensure-launch-coupons`
    | creates it in Stripe from these values, and the flow quotes the saving
    | from them, so the two always agree.
    |
    | A repeating coupon runs from the day it is applied, so on a trial it
    | covers the first charges after the trial ends: three monthly invoices,
    | or the one yearly invoice.
    |
    */

    'retention_coupon' => [
        'id' => env('STRIPE_RETENTION_COUPON_ID', 'wm_retention_30_3m'),
        'percent_off' => 30,
        'duration_in_months' => 3,
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
    | `trial_days` is the free trial every new subscription to the plan starts
    | with: the checkout sends it to Stripe and the checkout screens quote it.
    | The latam price inherits it (`PriceTiers::configuredPlansFor()`).
    |
    | `price`, `original_price` and `stripe_lookup_key` all come from the price
    | at the top and move as one unit — never hardcode one of them here, or the
    | price shown stops matching the price Stripe charges.
    |
    | The default tier's lookup keys still carry the `_high` suffix they were
    | given as the price experiment's variant tier, which won and became the
    | default price. Renaming them would make `stripe:sync-prices` transfer the
    | key onto a fresh price and move every live subscription's key for a
    | cosmetic gain; the suffix is an internal identifier no user ever sees.
    |
    */

    'plans' => [
        'monthly' => [
            'name' => 'Standard Monthly',
            'price' => $tier['monthly']['price'],
            'original_price' => $tier['monthly']['original_price'],
            'stripe_lookup_key' => $tier['monthly']['stripe_lookup_key'],
            'billing_period' => 'month',
            'trial_days' => 7,
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
            'price' => $tier['yearly']['price'],
            'original_price' => $tier['yearly']['original_price'],
            'stripe_lookup_key' => $tier['yearly']['stripe_lookup_key'],
            'billing_period' => 'year',
            'trial_days' => 14,
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
    | The plan every pricing surface preselects (the paywall, the onboarding
    | gates, the upgrade dialog, settings → billing, the landing page) and the
    | one checkout falls back to without a `?plan=`. `yearly` unless
    | `SUBSCRIPTIONS_DEFAULT_PLAN` names another plan above (env-only, no
    | deploy — `php artisan config:clear` after changing it). A value that is
    | not one of the plans falls back to `yearly`: a typo must not leave the
    | screens with nothing selected.
    |
    | It only changes what is selected first, never a price. An arm of the
    | `OnboardingGateCopy` experiment that preselects its own plan still wins
    | on its gate; the plan actually preselected on screen is what checkout
    | records as `preselected_plan`.
    |
    */

    'default_plan' => array_key_exists($defaultPlan, $tier) ? $defaultPlan : 'yearly',

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
