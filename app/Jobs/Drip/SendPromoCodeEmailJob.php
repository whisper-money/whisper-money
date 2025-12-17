<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\PromoCodeEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPromoCodeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public User $user)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        if ($this->user->hasReceivedEmail(DripEmailType::PromoCode)) {
            return;
        }

        if (! $this->user->isOnboarded()) {
            return;
        }

        if (! $this->user->transactions()->exists()) {
            return;
        }

        if ($this->user->hasProPlan()) {
            return;
        }

        Mail::to($this->user)->send(new PromoCodeEmail($this->user));

        UserMailLog::create([
            'user_id' => $this->user->id,
            'email_type' => DripEmailType::PromoCode,
            'sent_at' => now(),
        ]);
    }
}
