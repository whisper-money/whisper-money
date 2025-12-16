<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:test {email? : The email address to send the test to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test email to verify mail configuration (Resend or other)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email') ?? $this->ask('Enter the email address to send the test to');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Invalid email address provided.');

            return self::FAILURE;
        }

        $this->info('Sending test email to: '.$email);
        $this->info('Using mailer: '.config('mail.default'));

        try {
            Mail::raw(
                'This is a test email from Whisper Money. If you received this, your email configuration is working correctly!',
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('Test Email from Whisper Money');
                }
            );

            $this->info('✓ Test email sent successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('✗ Failed to send test email: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
