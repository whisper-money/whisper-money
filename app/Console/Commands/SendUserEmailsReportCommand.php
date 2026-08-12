<?php

namespace App\Console\Commands;

use App\Mail\UserEmailsReportEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendUserEmailsReportCommand extends Command
{
    protected $signature = 'email:user-emails-report';

    protected $description = 'Email a CSV with every active user\'s email address to the owners';

    public function handle(): int
    {
        /** @var array<int, string> $recipients */
        $recipients = config('mail.report_recipients');

        if ($recipients === []) {
            $this->error('REPORT_RECIPIENTS is not configured. Skipping the user emails report.');

            return self::FAILURE;
        }

        // The SoftDeletes global scope already leaves deleted users out.
        $emails = User::query()->orderBy('created_at')->pluck('email');

        $fileName = 'user-emails-'.Carbon::now()->format('Y-m-d').'.csv';

        Mail::to($recipients)->send(new UserEmailsReportEmail(
            csv: $this->toCsv($emails->all()),
            userCount: $emails->count(),
            fileName: $fileName,
        ));

        $this->info("Sent {$emails->count()} user email(s) as {$fileName}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $emails
     */
    private function toCsv(array $emails): string
    {
        $handle = fopen('php://temp', 'r+');

        // PHP 9 changes the default $escape, which would silently change our output.
        fputcsv($handle, ['email'], escape: '');

        foreach ($emails as $email) {
            fputcsv($handle, [$email], escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
