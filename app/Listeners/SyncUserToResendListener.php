<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ResendService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SyncUserToResendListener implements ShouldQueue
{
    public function __construct(public ResendService $resendService) {}

    public function handle(Verified $event): void
    {
        if (! config('services.resend.key')) {
            Log::warning('Resend API key not configured, skipping contact sync');

            return;
        }

        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $this->resendService->createContact($user);
    }
}
