<?php

namespace App\Console\Commands;

use App\Mail\Drip\FeedbackEmail;
use App\Mail\Drip\ImportHelpEmail;
use App\Mail\Drip\OnboardingReminderEmail;
use App\Mail\Drip\PromoCodeEmail;
use App\Mail\Drip\WelcomeEmail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestEmails extends Command
{
    protected $signature = 'emails:test {email : The email address to send all test emails to}';

    protected $description = 'Send all application emails to a specific email address for testing';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        $this->info("Sending all test emails to: {$email}");
        $this->newLine();

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
            ]
        );

        $emails = [
            'Welcome Email' => new WelcomeEmail($user),
            'Feedback Email' => new FeedbackEmail($user),
            'Import Help Email' => new ImportHelpEmail($user),
            'Onboarding Reminder Email' => new OnboardingReminderEmail($user),
            'Promo Code Email' => new PromoCodeEmail($user),
        ];

        foreach ($emails as $name => $mailable) {
            try {
                Mail::to($email)->send($mailable);
                $this->info("✓ Sent: {$name}");
            } catch (\Exception $e) {
                $this->error("✗ Failed to send {$name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('All emails sent successfully!');

        return self::SUCCESS;
    }
}
