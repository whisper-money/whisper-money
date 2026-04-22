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

    public function __construct(
        public User $user,
        public string $viewName,
        public string $emailIdentifier,
        public string $subject = 'Update from Whisper Money'
    ) {
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

        Mail::to($this->user)->send(
            new UpdateEmail($this->user, $this->viewName, $this->subject)
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
