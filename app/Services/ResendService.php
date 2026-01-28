<?php

namespace App\Services;

use App\Models\User;
use Resend;

class ResendService
{
    public function createContact(User $user): void
    {
        $apiKey = config('services.resend.key');

        $nameParts = explode(' ', $user->name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $resend = Resend::client($apiKey);

        $resend->contacts->create([
            'email' => $user->email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'unsubscribed' => false,
        ]);
    }
}
