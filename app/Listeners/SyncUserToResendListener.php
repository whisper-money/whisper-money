<?php

namespace App\Listeners;

use App\Services\ResendService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class SyncUserToResendListener implements ShouldQueue
{
    public function __construct(public ResendService $resendService) {}

    public function handle(Registered $event): void
    {
        if (! config('services.resend.key')) {
            Log::warning('Resend API key not configured, skipping contact sync');

            return;
        }

        $this->resendService->createContact($event->user);
    }
}
