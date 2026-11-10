<?php

namespace App\Console\Commands;

use App\Enums\DunningStep;
use App\Mail\Drip\AnnualRenewalEmail;
use App\Mail\Drip\FeedbackEmail;
use App\Mail\Drip\ImportHelpEmail;
use App\Mail\Drip\InactiveBankedEmail;
use App\Mail\Drip\InactiveNoBankEmail;
use App\Mail\Drip\OnboardingReminderEmail;
use App\Mail\Drip\PaymentActionRequiredEmail;
use App\Mail\Drip\PaymentFailedEmail;
use App\Mail\Drip\PromoCodeEmail;
use App\Mail\Drip\WelcomeEmail;
use App\Models\FailedInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendTestEmails extends Command
{
    protected $signature = 'email:test {email : The email address to send all test emails to}';

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
            'Inactive No Bank Email' => new InactiveNoBankEmail($user),
            'Inactive Banked Email' => new InactiveBankedEmail($user, ['newTransactions' => 37, 'uncategorized' => 5, 'spendingChange' => -12.0]),
            'Inactive Banked Email (nothing synced)' => new InactiveBankedEmail($user, ['newTransactions' => 0, 'uncategorized' => 0, 'spendingChange' => null]),
            'Annual Renewal Email' => new AnnualRenewalEmail($user, CarbonImmutable::now()->addDays(14), 5394, 'eur'),
            'Payment Failed Email (first charge after trial)' => new PaymentFailedEmail($user, $this->sampleFailedInvoice($user, isFirstChargeAfterTrial: true), DunningStep::First),
            'Payment Failed Email (renewal)' => new PaymentFailedEmail($user, $this->sampleFailedInvoice($user), DunningStep::First),
            'Payment Failed Email (reminder)' => new PaymentFailedEmail($user, $this->sampleFailedInvoice($user), DunningStep::Reminder),
            'Payment Failed Email (last notice)' => new PaymentFailedEmail($user, $this->sampleFailedInvoice($user), DunningStep::FinalNotice),
            'Payment Action Required Email' => new PaymentActionRequiredEmail($user, $this->sampleFailedInvoice($user, paymentIntentId: 'pi_test'), DunningStep::First),
            'Payment Action Required Email (last notice)' => new PaymentActionRequiredEmail($user, $this->sampleFailedInvoice($user, paymentIntentId: 'pi_test'), DunningStep::FinalNotice),
        ];

        foreach ($emails as $name => $mailable) {
            try {
                // Sent now: the drip mailables queue themselves, and the
                // sample invoices below are never saved.
                Mail::to($email)->sendNow($mailable);
                $this->info("✓ Sent: {$name}");
            } catch (\Exception $e) {
                $this->error("✗ Failed to send {$name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info('All emails sent successfully!');

        return self::SUCCESS;
    }

    /**
     * An unsaved invoice for the dunning emails, so testing them leaves
     * nothing behind for the reminders to pick up.
     */
    private function sampleFailedInvoice(User $user, bool $isFirstChargeAfterTrial = false, ?string $paymentIntentId = null): FailedInvoice
    {
        return new FailedInvoice([
            'user_id' => $user->id,
            'stripe_invoice_id' => 'in_test',
            'stripe_subscription_id' => 'sub_test',
            'amount_due' => 599,
            'currency' => 'eur',
            'billing_interval' => 'month',
            'is_first_charge_after_trial' => $isFirstChargeAfterTrial,
            'attempt_count' => 1,
            'payment_intent_id' => $paymentIntentId,
            'first_failed_at' => now(),
            'action_required_at' => $paymentIntentId === null ? null : now(),
        ]);
    }
}
