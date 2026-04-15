<?php

namespace App\Jobs;

use App\Enums\DripEmailType;
use App\Enums\TransactionSource;
use App\Mail\BankTransactionsSyncedEmail;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
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
        $lastSentMailLog = UserMailLog::query()
            ->where('user_id', $this->user->id)
            ->where('email_type', DripEmailType::BankTransactionsSynced)
            ->latest('sent_at')
            ->first();

        if ($lastSentMailLog?->email_identifier === $this->reportDate) {
            return;
        }

        $pendingTransactions = Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('source', TransactionSource::EnableBanking)
            ->when($lastSentMailLog?->sent_at, fn ($query, $lastSentAt) => $query->where('created_at', '>', $lastSentAt))
            ->whereHas('account.bankingConnection')
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
            'email_identifier' => $this->reportDate,
            'sent_at' => now(),
        ]);
    }

    public function uniqueId(): string
    {
        return $this->user->id.':'.$this->reportDate;
    }
}
