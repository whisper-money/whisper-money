<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->from(config('mail.from.address', 'hello@example.com'), 'Victor')
            ->subject('Verify Your Email - Whisper Money')
            ->markdown('mail.verify-email', [
                'userName' => $notifiable->name,
                'verificationUrl' => $verificationUrl,
            ]);
    }
}
