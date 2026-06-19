<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\PaywallFollowUpEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendPaywallFollowUpEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public User $user)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        if (! $this->user->canReceiveEmails()) {
            return;
        }

        if ($this->user->hasReceivedEmail(DripEmailType::PaywallFollowUp)) {
            return;
        }

        if ($this->user->hasProPlan()) {
            return;
        }

        if (! $this->user->bankingConnections()->exists()) {
            return;
        }

        Mail::to($this->user)->send(new PaywallFollowUpEmail($this->user));

        UserMailLog::create([
            'user_id' => $this->user->id,
            'email_type' => DripEmailType::PaywallFollowUp,
            'email_identifier' => DripEmailType::PaywallFollowUp->value,
            'sent_at' => now(),
        ]);
    }
}
