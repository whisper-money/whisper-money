<?php

namespace App\Console\Commands;

use App\Actions\Subscription\RefundSelfServe;
use App\Features\SubscriptionExperiment;
use App\Models\User;
use App\Services\Subscriptions\ExperimentOffer;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Laravel\Pennant\Feature;

/**
 * Live sandbox check for the pay_now self-service refund — the one path that
 * Pest tests can only mock. It creates a real, immediately-charged subscription
 * against the Stripe test environment, runs the actual RefundSelfServe action,
 * and confirms via the Stripe API that the charge was refunded and the
 * subscription canceled. Run before flipping SUBSCRIPTION_EXPERIMENT_STARTED_AT.
 *
 * Refuses to run against anything but Stripe test keys.
 */
class VerifyRefundFlowCommand extends Command
{
    protected $signature = 'stripe:verify-refund';

    protected $description = 'Verify the pay_now self-service refund end-to-end against the Stripe sandbox';

    public function __construct(private ExperimentOffer $offer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (app()->isProduction() || ! str_starts_with((string) config('cashier.secret'), 'sk_test')) {
            $this->error('Refusing to run: this command requires Stripe test keys and a non-production environment.');

            return self::FAILURE;
        }

        config(['subscriptions.tax_rates' => []]);

        $passed = true;
        $check = function (string $label, bool $ok) use (&$passed): void {
            $this->line(($ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>').'  '.$label);
            $passed = $passed && $ok;
        };

        $lookup = config('subscriptions.plans.monthly.stripe_lookup_key');
        $prices = Cashier::stripe()->prices->all(['lookup_keys' => [$lookup], 'limit' => 1]);
        $priceId = $prices->data[0]->id ?? null;

        if ($priceId === null) {
            $this->error("No Stripe price found for lookup key '{$lookup}'.");

            return self::FAILURE;
        }

        $user = User::factory()->create([
            'email' => 'refund-sandbox-'.uniqid().'@whisper.test',
            'created_at' => now(),
        ]);
        Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::PAY_NOW);

        try {
            $user->newSubscription('default', $priceId)->create('pm_card_visa');

            $subscription = $user->subscription('default');
            $check('subscription active after immediate charge', $subscription->active() && $subscription->stripe_status === 'active');
            $check('canSelfRefund is true before refund', $this->offer->canSelfRefund($user));

            $paymentIntentId = $subscription->latestPayment()?->asStripePaymentIntent()->id;
            $check('latestPayment() resolves a payment intent', $paymentIntentId !== null);

            app(RefundSelfServe::class)->handle($user->fresh());

            $subscription = $user->subscription('default')->fresh();
            $check('refunded_at is stamped', $subscription->refunded_at !== null);
            $check('subscription is canceled', $subscription->canceled());
            $check('canSelfRefund is false after refund', ! $this->offer->canSelfRefund($user->fresh()));

            $intent = Cashier::stripe()->paymentIntents->retrieve($paymentIntentId, ['expand' => ['latest_charge']]);
            $charge = $intent->latest_charge;
            $check('Stripe charge shows a full refund', is_object($charge) && $charge->refunded === true);
            $this->line('  amount_refunded='.($charge->amount_refunded ?? 'n/a'));
        } catch (\Throwable $exception) {
            $this->error($exception::class.': '.$exception->getMessage());
            $passed = false;
        } finally {
            try {
                if ($user->hasStripeId()) {
                    Cashier::stripe()->customers->delete($user->stripe_id);
                }
            } catch (\Throwable) {
                // best-effort sandbox cleanup
            }
            $user->forceDelete();
        }

        $this->newLine();
        $this->{$passed ? 'info' : 'error'}($passed ? 'Refund flow verified.' : 'Refund flow verification FAILED.');

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
