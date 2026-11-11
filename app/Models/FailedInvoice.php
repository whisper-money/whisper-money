<?php

namespace App\Models;

use App\Listeners\SyncFailedInvoicesFromStripe;
use Carbon\CarbonInterface;
use Database\Factories\FailedInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subscription invoice Stripe could not collect, as the dunning emails and
 * the in-app payment banner need it: kept in step from Stripe's webhooks by
 * {@see SyncFailedInvoicesFromStripe}, so nothing here ever has
 * to ask Stripe which invoices are still owed.
 *
 * @property string $id
 * @property string $user_id
 * @property string $stripe_invoice_id
 * @property string $stripe_subscription_id
 * @property int $amount_due
 * @property string $currency
 * @property ?string $billing_interval
 * @property bool $is_first_charge_after_trial
 * @property int $attempt_count
 * @property ?string $payment_intent_id
 * @property CarbonInterface $first_failed_at
 * @property ?CarbonInterface $next_payment_attempt_at
 * @property ?CarbonInterface $action_required_at
 * @property ?CarbonInterface $resolved_at
 */
class FailedInvoice extends Model
{
    /** @use HasFactory<FailedInvoiceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'stripe_invoice_id',
        'stripe_subscription_id',
        'amount_due',
        'currency',
        'billing_interval',
        'is_first_charge_after_trial',
        'attempt_count',
        'payment_intent_id',
        'first_failed_at',
        'next_payment_attempt_at',
        'action_required_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_due' => 'integer',
            'is_first_charge_after_trial' => 'boolean',
            'attempt_count' => 'integer',
            'first_failed_at' => 'datetime',
            'next_payment_attempt_at' => 'datetime',
            'action_required_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The local copy of the subscription the invoice bills, which Cashier
     * keeps in step with Stripe.
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'stripe_subscription_id', 'stripe_id');
    }

    /**
     * Invoices Stripe is still trying to collect.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    /**
     * Invoices the bank holds for a 3D Secure confirmation, with a payment
     * intent for Cashier's payment page to confirm.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingConfirmation(Builder $query): void
    {
        $query->whereNotNull('action_required_at')->whereNotNull('payment_intent_id');
    }

    /**
     * Cashier's page where the customer confirms the payment with their bank,
     * sending them back to the billing settings afterwards.
     *
     * @param  array<string, string>  $returnQuery  Query string for the page they come back to (UTM parameters).
     */
    public function confirmationUrl(array $returnQuery = []): string
    {
        return route('cashier.payment', [
            'id' => $this->payment_intent_id,
            'redirect' => route('settings.billing', $returnQuery),
        ]);
    }
}
