<?php

namespace App\Jobs;

use App\Enums\DripEmailType;
use App\Enums\TransactionSource;
use App\Mail\BankTransactionsSyncedEmail;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use DateTimeZone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendDailyBankTransactionsSyncedEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public $backoff = [2, 5, 10, 30];

    public function __construct(
        public User $user,
        public string $reportDate,
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        if (! $this->user->canReceiveEmails()) {
            return;
        }

        if (! $this->user->wantsBankTransactionsSyncedEmail()) {
            return;
        }

        $localReportDate = $this->localReportDate();
        $lastSentMailLog = UserMailLog::query()
            ->where('user_id', $this->user->id)
            ->where('email_type', DripEmailType::BankTransactionsSynced)
            ->latest('sent_at')
            ->first();

        if ($lastSentMailLog?->email_identifier === $localReportDate) {
            return;
        }

        $quietHoursDelay = $this->quietHoursDelayInSeconds();

        if ($quietHoursDelay !== null) {
            $this->release($quietHoursDelay);

            return;
        }

        $pendingTransactions = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('source', TransactionSource::EnableBanking)
            ->when($lastSentMailLog?->sent_at, fn ($query, $lastSentAt) => $query->where('created_at', '>', $lastSentAt))
            ->whereHas('account.bankingConnection', function ($query) {
                $query->where(function ($query) {
                    $query->whereNull('bank_transactions_email_cutoff_at')
                        ->orWhereColumn('bank_transactions_email_cutoff_at', '<', 'transactions.created_at');
                });
            })
            ->with('account.bank')
            ->get();

        if ($pendingTransactions->isEmpty()) {
            return;
        }

        $transactionsPerBank = $pendingTransactions
            ->groupBy(fn (Transaction $transaction) => $transaction->account->bank->name ?? __('Unknown Bank'))
            ->map(fn ($transactions) => $transactions->count())
            ->sortKeys()
            ->all();

        Mail::to($this->user)->send(new BankTransactionsSyncedEmail(
            $this->user,
            $pendingTransactions->count(),
            $transactionsPerBank,
        ));

        UserMailLog::create([
            'user_id' => $this->user->id,
            'email_type' => DripEmailType::BankTransactionsSynced,
            'email_identifier' => $localReportDate,
            'sent_at' => now(),
        ]);
    }

    public function uniqueId(): string
    {
        return $this->user->id.':'.$this->reportDate;
    }

    private function localReportDate(): string
    {
        return now($this->reportingTimezone())->toDateString();
    }

    private function quietHoursDelayInSeconds(): ?int
    {
        $timezone = $this->userTimezone();

        if ($timezone === null) {
            return null;
        }

        $localNow = now($timezone);
        $localHour = (int) $localNow->format('G');

        if ($localHour >= 8 && $localHour < 23) {
            return null;
        }

        $nextAllowedAt = $localHour >= 23
            ? $localNow->copy()->addDay()->startOfDay()->addHours(8)
            : $localNow->copy()->startOfDay()->addHours(8);

        return $nextAllowedAt->getTimestamp() - $localNow->getTimestamp();
    }

    private function reportingTimezone(): string
    {
        return $this->userTimezone() ?? config('app.timezone', 'UTC');
    }

    private function userTimezone(): ?string
    {
        $timezone = $this->user->timezone;

        if ($timezone === null) {
            return null;
        }

        try {
            new DateTimeZone($timezone);

            return $timezone;
        } catch (\Exception) {
            return null;
        }
    }
}
