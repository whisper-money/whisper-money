<?php

namespace App\Mail;

use App\Models\SpaceInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

class SpaceInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var int */
    public $tries = 5;

    /** @var array<int, int> */
    public $backoff = [2, 5, 10, 30];

    public function __construct(public SpaceInvitation $invitation)
    {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':inviter invited you to :space on Whisper Money', [
                'inviter' => User::query()->whereKey($this->invitation->invited_by_id)->value('name') ?? __('Someone'),
                'space' => $this->invitation->space->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.space-invitation',
            with: [
                'spaceName' => $this->invitation->space->name,
                'inviterName' => User::query()->whereKey($this->invitation->invited_by_id)->value('name') ?? __('A Whisper Money user'),
                'acceptUrl' => route('spaces.invitations.accept', $this->invitation->token),
            ],
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
