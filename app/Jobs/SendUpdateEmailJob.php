<?php

namespace App\Jobs;

use App\Enums\DripEmailType;
use App\Mail\UpdateEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendUpdateEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public $backoff = [2, 5, 10, 30];

    /**
     * Separates a campaign from a notice. Campaigns are the default and obey
     * "Product news and offers"; a notice — an account about to be deleted, say
     * — is sent to say something the reader needs to know and is not something
     * they opted into hearing.
     *
     * A flag rather than the email type, because every send here logs as
     * {@see DripEmailType::Update}: the type cannot tell the two apart, only the
     * caller can.
     *
     * Declared rather than promoted so the default survives a stale payload. The
     * queue rebuilds a job through {@see SerializesModels::__unserialize()},
     * which skips every property the payload does not carry, and a promoted
     * parameter default cannot fill the gap because the constructor never runs.
     * Jobs queued before this flag existed came back with it uninitialized.
     */
    public bool $marketing = true;

    public function __construct(
        public User $user,
        public string $viewName,
        public string $emailIdentifier,
        public string $subject = 'Update from Whisper Money',
        bool $marketing = true,
    ) {
        $this->marketing = $marketing;

        $this->onQueue('emails');
    }

    public function handle(): void
    {
        if (! $this->user->canReceiveEmails()) {
            return;
        }

        if ($this->hasReceivedUpdate()) {
            return;
        }

        if ($this->marketing && ! $this->user->wantsMarketingEmails()) {
            return;
        }

        Mail::to($this->user)->send(
            new UpdateEmail($this->user, $this->viewName, $this->subject, $this->marketing)
        );

        UserMailLog::create([
            'user_id' => $this->user->id,
            'email_type' => DripEmailType::Update,
            'email_identifier' => $this->emailIdentifier,
            'sent_at' => now(),
        ]);
    }

    protected function hasReceivedUpdate(): bool
    {
        return UserMailLog::where('user_id', $this->user->id)
            ->where('email_type', DripEmailType::Update)
            ->where('email_identifier', $this->emailIdentifier)
            ->exists();
    }
}
