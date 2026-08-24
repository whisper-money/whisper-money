<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * A one-off notice about one bank, written by hand as a Markdown file and sent
 * by `banking:notify-users`.
 *
 * The bodies travel as text rather than as a path: a queue worker is not
 * guaranteed to have the file the operator ran the command with, and the file
 * can be edited between dispatch and delivery.
 *
 * Reply-to is the shared founders inbox, because a notice about a bank invites
 * people to write back.
 */
class BankNoticeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var int */
    public $tries = 5;

    /** @var array<int, int> */
    public $backoff = [2, 5, 10, 30];

    /**
     * @param  array<string, string>  $notices  Markdown bodies keyed by locale, always including `en`.
     */
    public function __construct(
        public User $user,
        public array $notices,
    ) {
        $this->onQueue('emails');
    }

    /**
     * The subject is the heading the notice opens with, so it is written in the
     * same file, and the same language, as the body it belongs to. Laravel
     * hydrates the envelope inside withLocale(), so that is the recipient's
     * language.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) self::subjectOf($this->body()),
            replyTo: [new Address(
                config('mail.drip_from.address', 'hi@whisper.money'),
                config('mail.drip_from.name', 'Whisper Money'),
            )],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.bank-notice',
            with: ['body' => $this->body()],
        );
    }

    /**
     * The notice this recipient reads: their own language when the operator
     * wrote one, and the mandatory English file otherwise.
     */
    public function body(): string
    {
        $notice = $this->notices[self::localeFor($this->notices, app()->getLocale())];

        return str_replace(':name', (string) $this->user->name, $notice);
    }

    /**
     * Which of the notices a locale reads. Shared with the command so the
     * recipient table it prints cannot disagree with what goes out.
     *
     * @param  array<string, string>  $notices
     */
    public static function localeFor(array $notices, string $locale): string
    {
        return isset($notices[$locale]) ? $locale : 'en';
    }

    /**
     * The `# Heading` a notice opens with, or null when it does not open with
     * one, which is what the command refuses to send.
     */
    public static function subjectOf(string $notice): ?string
    {
        $heading = trim(Str::before(ltrim($notice), "\n"));

        return Str::startsWith($heading, '# ') ? trim(Str::after($heading, '# ')) : null;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }
}
