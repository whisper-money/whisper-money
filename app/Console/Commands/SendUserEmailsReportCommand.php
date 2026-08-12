<?php

namespace App\Console\Commands;

use App\Mail\UserEmailsReportEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class SendUserEmailsReportCommand extends Command
{
    protected $signature = 'stats:user-emails';

    protected $description = 'Email a CSV with every active user\'s email address to the product owners';

    /**
     * ponytail: hardcoded so the report can never silently no-op on a missing
     * env var. Move to config if the recipients ever need to differ per environment.
     *
     * @var array<int, string>
     */
    private const RECIPIENTS = ['victoor89@gmail.com', 'invernovah@gmail.com'];

    public function handle(): int
    {
        // The SoftDeletes global scope already leaves deleted users out.
        $emails = User::query()->orderBy('created_at')->pluck('email');

        $fileName = 'user-emails-'.Carbon::now()->format('Y-m-d').'.csv';

        Mail::to(self::RECIPIENTS)->send(new UserEmailsReportEmail(
            csv: $this->toCsv($emails->all()),
            userCount: $emails->count(),
            fileName: $fileName,
        ));

        $this->info("Sent {$emails->count()} user email(s) as {$fileName} to ".implode(', ', self::RECIPIENTS).'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $emails
     */
    private function toCsv(array $emails): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['email']);

        foreach ($emails as $email) {
            fputcsv($handle, [$email]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
