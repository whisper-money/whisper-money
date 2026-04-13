<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserLead;
use Resend;
use Resend\Exceptions\ErrorException;

class ResendService
{
    public function createContact(User $user): void
    {
        $apiKey = config('services.resend.key');

        $nameParts = explode(' ', $user->name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $resend = $this->client($apiKey);

        $resend->contacts->create([
            'email' => $user->email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'unsubscribed' => false,
        ]);
    }

    public function syncLead(UserLead $lead): void
    {
        $apiKey = config('services.resend.key');
        $segmentId = config('services.resend.leads_segment_id');

        $resend = $this->client($apiKey);

        try {
            $resend->contacts->create([
                'email' => $lead->email,
                'unsubscribed' => false,
                'segments' => [
                    ['id' => $segmentId],
                ],
            ]);
        } catch (ErrorException $exception) {
            if (! $this->contactAlreadyExists($exception)) {
                throw $exception;
            }

            $resend->contacts->segments->add($lead->email, $segmentId);
        }
    }

    private function contactAlreadyExists(ErrorException $exception): bool
    {
        return $exception->getErrorCode() === 409
            || str_contains(strtolower($exception->getMessage()), 'already exists');
    }

    protected function client(string $apiKey): object
    {
        return Resend::client($apiKey);
    }
}
