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

    /**
     * Days a nudge email silences the other nudges for.
     */
    private const NUDGE_COOLDOWN_DAYS = 5;

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

        if ($this->isMutedByAnotherNudge()) {
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

    /**
     * Nudge emails share a cooldown, so a user never gets two "come back and
     * update your data" messages in the same week.
     *
     * It matters most for the manual-only user, who is the audience of nearly
     * every nudge we have: `inactive_no_bank` fires seven days after their last
     * visit, and the monthly reminder fires on the 3rd, so they collide by
     * design. Whichever arrives first wins and the other stands down until the
     * next cycle. Operational mail is not a nudge and is unaffected.
     *
     * A nudge never silences itself: its own cadence is its own business, and it
     * already has {@see emailIdentifier()} to stop it repeating.
     */
    private function isMutedByAnotherNudge(): bool
    {
        if (! in_array($this->emailType(), DripEmailType::nudges(), true)) {
            return false;
        }

        return $this->user->hasReceivedNudgeSince(
            now()->subDays(self::NUDGE_COOLDOWN_DAYS),
            except: $this->emailType(),
        );
    }

    private function hasAlreadyBeenSent(): bool
    {
        return $this->user->mailLogs()
            ->where('email_type', $this->emailType())
            ->where('email_identifier', $this->emailIdentifier())
            ->exists();
    }
}
