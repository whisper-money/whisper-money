<?php

use App\Support\PriceTiers;

/*
|--------------------------------------------------------------------------
| Price Tier
|--------------------------------------------------------------------------
|
| Which of the pre-declared price tiers the app quotes and charges. `high` is
| the current price; `low` is the pre-experiment one. The tier supplies the
| displayed price, the struck-through price and the Stripe lookup key as one
| unit, so what is shown is always what is charged (env-only, no deploy —
| `php artisan config:clear` after changing it). An unknown value falls back
| to `high`. The tiers themselves live in `App\Support\PriceTiers`.
|
*/

$tier = PriceTiers::plansFor(env('SUBSCRIPTION_PRICE_TIER', PriceTiers::DEFAULT));

/*
|--------------------------------------------------------------------------
| Pay Now
|--------------------------------------------------------------------------
|
| Whether the paid plan is charged in full at signup. Off, which is the
| default, the plans carry the trial they have always carried and the checkout
| takes no money today. On, the trial is 0 on both plans, the charge lands
| immediately and the way back out is the self-service refund window declared
| below — the three days and the button that spends them.
|
| It is a switch on `trial_days` and nothing else, because `trial_days` is what
| every other part reads: `ExperimentOffer` calls a variant upfront when it
| zeroes the trial, the checkout screens say "charged today" or "free for N
| days" off the selected plan, and the refund button appears only for someone
| who was actually charged. One value to flip, and no second source of truth to
| disagree with it.
|
| It is only the *default*: `STRIPE_PRO_*_TRIAL_DAYS` still wins where it is
| set, and an experiment variant still overrides both. There is deliberately no
| `subscriptions.pay_now` key to read — code that wants to know whether a user
| pays upfront asks `ExperimentOffer`, which answers for that user's variant
| rather than for the environment.
|
*/

$payNow = filter_var(env('SUBSCRIPTION_PAY_NOW', false), FILTER_VALIDATE_BOOLEAN);

/*
| A trial of 0 is a real value, so `env()`'s own default cannot be used: it only
| applies when the variable is absent, and `?:` would swallow the deliberate 0
| as well. Present-but-blank counts as absent, the way clearing a value in a
| hosting panel is meant to.
*/
$trialDays = static fn (string $key, int $default): int => is_numeric($raw = env($key)) ? (int) $raw : $default;

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
    | Subscription Experiment
    |--------------------------------------------------------------------------
    |
    | A/B test on how the paid plan is offered. Users who register on or after
    | `started_at` are split evenly across `variants` by a stable hash of their
    | id; everyone who registered earlier stays "legacy" and keeps the plan
    | defaults. While `started_at` is null or blank, or no variant is declared,
    | the experiment is off and every user is legacy — this block is inert until
    | an experiment fills it in.
    |
    | Each variant may override `trial_days` per plan. A variant whose trial is
    | 0 on every plan charges upfront, which is what opens the self-service
    | refund window (`refund_window_days` from the subscription date).
    |
    | The split is positional: adding, removing or reordering a variant
    | reassigns existing users, so only change `variants` between experiments.
    |
    | The declared experiment is the one the pay-now redesign asks for: two
    | branches, `pay_now` (the plan defaults below, charged in full at signup)
    | against `trial`, which restores the free trial the plans used to carry.
    | The primary metric is subscribed -> still active, read per variant by
    | `stats:experiment-funnel`.
    |
    | It is declared but NOT running: `started_at` is unset, which leaves every
    | user legacy and every user on the plan defaults. Turn it on only once the
    | redesign has been live for weeks — starting it while the flow is still
    | changing splits the cohort across two different products and the result
    | cannot be read.
    |
    */

    'experiment' => [
        // `?:`, not `env()`'s default: a variable that is present but blank
        // yields '' rather than null, and '' would read as a declared start
        // date — every user assigned to an arm the moment the key exists in a
        // hosting panel. Blanking the value is the ordinary way to leave the
        // experiment off, so it has to mean the same as removing the line.
        'started_at' => env('SUBSCRIPTION_EXPERIMENT_STARTED_AT') ?: null,
        // Once a winner is chosen, set this to one of the variant keys to give
        // every user that variant and end the split (env-only, no deploy).
        'force_variant' => env('SUBSCRIPTION_EXPERIMENT_FORCE_VARIANT'),
        'refund_window_days' => (int) env('SUBSCRIPTION_EXPERIMENT_REFUND_WINDOW_DAYS', 3),
        'variants' => [
            'pay_now' => ['trial_days' => ['monthly' => 0, 'yearly' => 0]],
            'trial' => ['trial_days' => ['monthly' => 7, 'yearly' => 15]],
        ],
    ],

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
    | `trial_days` comes from the `SUBSCRIPTION_PAY_NOW` switch above: the trial
    | the plans have always carried while it is off, 0 while it is on. Setting
    | `STRIPE_PRO_*_TRIAL_DAYS` pins a plan's trial regardless of the switch, and
    | the `trial` experiment variant is how to put a trial in front of half the
    | signups instead of all of them.
    |
    | `price`, `original_price` and `stripe_lookup_key` all come from the tier
    | selected above and move as one unit — never hardcode one of them here, or
    | the price shown stops matching the price Stripe charges. Overriding
    | `STRIPE_PRO_*_LOOKUP_KEY` breaks that welding too, so only do it for a key
    | that genuinely differs per environment.
    |
    | Those overrides fall back with `?:`, not with `env()`'s default, because a
    | variable that is present but blank yields '' rather than the default — and
    | an empty lookup key makes checkout abort with "Invalid plan selected" for
    | everyone. Blanking the value in a hosting panel is the ordinary way to
    | clear an override, so it has to mean the same as removing the line.
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
            'stripe_lookup_key' => env('STRIPE_PRO_MONTHLY_LOOKUP_KEY') ?: $tier['monthly']['stripe_lookup_key'],
            'billing_period' => 'month',
            'trial_days' => $trialDays('STRIPE_PRO_MONTHLY_TRIAL_DAYS', $payNow ? 0 : 7),
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
            'stripe_lookup_key' => env('STRIPE_PRO_YEARLY_LOOKUP_KEY') ?: $tier['yearly']['stripe_lookup_key'],
            'billing_period' => 'year',
            'trial_days' => $trialDays('STRIPE_PRO_YEARLY_TRIAL_DAYS', $payNow ? 0 : 15),
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
