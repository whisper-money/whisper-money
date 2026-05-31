<?php

namespace App\Services\Stripe;

use Laravel\Cashier\Cashier;
use Throwable;

class StripeCustomerResolver
{
    public function label(?string $customerId): string
    {
        if (blank($customerId)) {
            return 'unknown';
        }

        try {
            $customer = Cashier::stripe()->customers->retrieve($customerId);

            $name = is_string($customer->name) ? trim($customer->name) : '';
            $email = is_string($customer->email) ? trim($customer->email) : '';

            return match (true) {
                $name !== '' && $email !== '' => "{$name} ({$email})",
                $email !== '' => $email,
                $name !== '' => $name,
                default => $customerId,
            };
        } catch (Throwable) {
            return $customerId;
        }
    }
}
