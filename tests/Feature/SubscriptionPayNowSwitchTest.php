<?php

declare(strict_types=1);

/**
 * `SUBSCRIPTION_PAY_NOW` decides whether the plans are charged in full at signup
 * or start a trial, and it is read once while the config file is evaluated — so
 * it is exercised by evaluating that file again with the environment set, not by
 * setting a config key a booted app would already be past.
 *
 * Everything downstream hangs off the `trial_days` this produces: `ExperimentOffer`
 * calls a variant upfront when both plans are 0, the checkout screens pick
 * "charged today" or "free for N days" from it, and the refund button follows.
 * Pin the two numbers and the rest cannot drift on its own.
 *
 * @param  array<string, string|null>  $env
 * @return array{monthly: int, yearly: int}
 */
function trialDaysWith(array $env): array
{
    $keys = ['SUBSCRIPTION_PAY_NOW', 'STRIPE_PRO_MONTHLY_TRIAL_DAYS', 'STRIPE_PRO_YEARLY_TRIAL_DAYS'];
    $restore = [];

    foreach ($keys as $key) {
        $restore[$key] = $_ENV[$key] ?? null;
        $value = $env[$key] ?? null;

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            continue;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        $config = require config_path('subscriptions.php');

        return [
            'monthly' => $config['plans']['monthly']['trial_days'],
            'yearly' => $config['plans']['yearly']['trial_days'],
        ];
    } finally {
        foreach ($restore as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);

                continue;
            }

            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

it('sells the trial while the switch is off', function () {
    expect(trialDaysWith([]))->toBe(['monthly' => 7, 'yearly' => 14]);
});

it('charges in full at signup while the switch is on', function () {
    expect(trialDaysWith(['SUBSCRIPTION_PAY_NOW' => 'true']))
        ->toBe(['monthly' => 0, 'yearly' => 0]);
});

it('reads anything but a true as off', function (string $value) {
    expect(trialDaysWith(['SUBSCRIPTION_PAY_NOW' => $value]))
        ->toBe(['monthly' => 7, 'yearly' => 14]);
})->with(['false', '0', '', 'nonsense']);

/**
 * A pinned trial outranks the switch in both directions — the point of keeping
 * the per-plan variables is an environment that wants a length of its own.
 */
it('lets a pinned trial override the switch', function () {
    expect(trialDaysWith([
        'SUBSCRIPTION_PAY_NOW' => 'true',
        'STRIPE_PRO_MONTHLY_TRIAL_DAYS' => '14',
    ]))->toBe(['monthly' => 14, 'yearly' => 0]);
});

it('lets a pinned zero charge one plan upfront with the switch off', function () {
    expect(trialDaysWith(['STRIPE_PRO_MONTHLY_TRIAL_DAYS' => '0']))
        ->toBe(['monthly' => 0, 'yearly' => 14]);
});

/**
 * Clearing a value in a hosting panel leaves it present and empty. That has to
 * mean "let the switch decide" rather than "a trial of zero days", which is why
 * the default is not `env()`'s own — a deliberate 0 has to survive it.
 */
it('treats a blank pinned trial as absent', function () {
    expect(trialDaysWith(['STRIPE_PRO_MONTHLY_TRIAL_DAYS' => '']))
        ->toBe(['monthly' => 7, 'yearly' => 14]);
});
