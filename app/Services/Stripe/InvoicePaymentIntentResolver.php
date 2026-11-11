<?php

namespace App\Services\Stripe;

use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

/**
 * The payment intent behind an invoice's latest payment attempt, which is what
 * Cashier's payment page confirms.
 *
 * Older API versions put it on the invoice as `payment_intent`; from basil on
 * it sits under the invoice's `payments`, which webhook payloads only carry
 * when expanded, so the list is asked for when the payload has neither.
 */
class InvoicePaymentIntentResolver
{
    /**
     * @param  array<string, mixed>  $invoice  The invoice object of a webhook payload.
     */
    public function for(array $invoice): ?string
    {
        $fromPayload = $invoice['payment_intent'] ?? $this->latestFrom($invoice['payments']['data'] ?? []);

        if (is_string($fromPayload) && $fromPayload !== '') {
            return $fromPayload;
        }

        return is_string($invoice['id'] ?? null) ? $this->fetch($invoice['id']) : null;
    }

    private function fetch(string $invoiceId): ?string
    {
        try {
            $payments = Cashier::stripe()->invoicePayments->all(['invoice' => $invoiceId, 'limit' => 10]);
        } catch (ApiErrorException $exception) {
            report($exception);

            return null;
        }

        return $this->latestFrom($payments->toArray()['data'] ?? []);
    }

    /**
     * The open attempt if there is one, the newest otherwise.
     *
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function latestFrom(array $payments): ?string
    {
        $open = array_filter($payments, fn (array $payment): bool => ($payment['status'] ?? null) === 'open');
        $latest = end($open) ?: end($payments) ?: [];
        $intent = $latest['payment']['payment_intent'] ?? null;

        return is_string($intent) && $intent !== '' ? $intent : null;
    }
}
