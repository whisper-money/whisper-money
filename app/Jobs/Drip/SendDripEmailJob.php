<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

abstract class SendDripEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public User $user)
    {
        $this->onQueue('emails');
    }

    abstract protected function emailType(): DripEmailType;

    abstract protected function buildMail(): Mailable;

    /**
     * Dedupe key stored alongside the email type. Emails that may legitimately
     * be sent to the same user more than once override this with a per-send
     * value (e.g. a date); the default makes them one-per-user-ever.
     */
    protected function emailIdentifier(): string
    {
        return $this->emailType()->value;
    }

    /**
     * Per-email eligibility checks beyond the shared "can receive" and
     * "not already sent" guards.
     */
    protected function shouldSend(): bool
    {
        return true;
    }

    public function handle(): void
    {
        if (! $this->user->canReceiveEmails()) {
            return;
        }

        if ($this->hasAlreadyBeenSent()) {
            return;
        }

        if (! $this->shouldSend()) {
            return;
        }

        Mail::to($this->user)->send($this->buildMail());

        UserMailLog::create([
            'user_id' => $this->user->id,
            'email_type' => $this->emailType(),
            'email_identifier' => $this->emailIdentifier(),
            'sent_at' => now(),
        ]);
    }

    private function hasAlreadyBeenSent(): bool
    {
        return $this->user->mailLogs()
            ->where('email_type', $this->emailType())
            ->where('email_identifier', $this->emailIdentifier())
            ->exists();
    }
}
