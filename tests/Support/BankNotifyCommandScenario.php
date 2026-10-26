<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Console\Commands\Concerns\NotifiesBankUsers;
use App\Mail\BankConnectFailedEmail;
use App\Mail\BankNoticeEmail;
use App\Mail\BankOutageEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * One `banking:notify-*` command with a bank already seeded so that a run of it
 * has someone to email. The three commands share the review, confirm, send and
 * dedupe steps of {@see NotifiesBankUsers}, so those steps are tested once
 * over all three; what tells the commands apart (who counts as affected) stays
 * in each command's own test file.
 */
final class BankNotifyCommandScenario
{
    /**
     * @param  array<string, string>  $arguments
     * @param  class-string  $mail
     */
    private function __construct(
        public readonly string $command,
        public readonly array $arguments,
        public readonly string $mail,
        public readonly int $recipients,
        public readonly string $recipientEmail,
        public readonly string $confirmation,
        public readonly string $alreadyNotified,
    ) {}

    /**
     * One user with a working connection to a bank that is down.
     */
    public static function outage(): self
    {
        self::connectedUser('outage@example.com', 'QA Outage Bank');

        return new self(
            command: 'banking:notify-outage',
            arguments: ['aspsp' => 'QA Outage Bank'],
            mail: BankOutageEmail::class,
            recipients: 1,
            recipientEmail: 'outage@example.com',
            confirmation: 'Send the QA Outage Bank (ES) outage notice to 1 user(s)?',
            alreadyNotified: 'Nobody to notify',
        );
    }

    /**
     * Two users whose authorization the bank sent back as an error: the command
     * refuses to call a bank broken on the word of a single user.
     */
    public static function connectFailure(): self
    {
        foreach (['connect-failure@example.com', 'other-victim@example.com'] as $email) {
            BankingConnection::factory()->for(User::factory()->create(['email' => $email]))->pending()->create([
                'aspsp_name' => 'QA Broken Bank',
                'aspsp_country' => 'ES',
            ])->delete();
        }

        return new self(
            command: 'banking:notify-connect-failure',
            arguments: ['aspsp' => 'QA Broken Bank'],
            mail: BankConnectFailedEmail::class,
            recipients: 2,
            recipientEmail: 'connect-failure@example.com',
            confirmation: 'Tell 2 user(s) that QA Broken Bank (ES) cannot be connected?',
            alreadyNotified: 'Nobody to notify',
        );
    }

    /**
     * One Spanish-speaking user connected to a bank, and a notice written in
     * English and Spanish to files in the given directory.
     */
    public static function notice(string $directory): self
    {
        self::connectedUser('notice@example.com', 'QA Notice Bank', ['locale' => 'es']);

        $base = $directory.'/a-notice';
        File::put("{$base}.en.md", "# Heads up about your bank\n\nHi :name, here is the news.");
        File::put("{$base}.es.md", "# Atención con tu banco\n\nHola :name, hay novedades.");

        return new self(
            command: 'banking:notify-users',
            arguments: ['aspsp' => 'QA Notice Bank', 'notice' => $base],
            mail: BankNoticeEmail::class,
            recipients: 1,
            recipientEmail: 'notice@example.com',
            confirmation: 'Send "Heads up about your bank" to 1 user(s) connected to QA Notice Bank (ES)?',
            alreadyNotified: 'everyone connected already got this notice',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function connectedUser(string $email, string $bank, array $attributes = []): void
    {
        BankingConnection::factory()->for(User::factory()->create(['email' => $email, ...$attributes]))->create([
            'aspsp_name' => $bank,
            'aspsp_country' => 'ES',
        ]);
    }
}
