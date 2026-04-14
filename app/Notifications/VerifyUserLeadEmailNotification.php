<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyUserLeadEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Delete the job if the notifiable model no longer exists.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    public function __construct(private readonly string $verificationUrl)
    {
        $this->onQueue('emails');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Confirm your waitlist spot - Whisper Money'))
            ->markdown('mail.verify-user-lead-email', [
                'verificationUrl' => $this->verificationUrl,
            ]);
    }
}
