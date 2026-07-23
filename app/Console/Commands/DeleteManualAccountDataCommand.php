<?php

namespace App\Console\Commands;

use App\Models\AccountBalance;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;

class DeleteManualAccountDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:delete-manual-account-data {email : The email address of the user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all transactions and balances of a user\'s non-connected accounts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $this->error("User with email '{$email}' not found.");

            return self::FAILURE;
        }

        $accountIds = $user->accounts()->whereNull('banking_connection_id')->pluck('id');

        if ($accountIds->isEmpty()) {
            $this->info("User '{$email}' has no non-connected accounts.");

            return self::SUCCESS;
        }

        $transactionCount = Transaction::query()->whereIn('account_id', $accountIds)->count();
        $balanceCount = AccountBalance::query()->whereIn('account_id', $accountIds)->count();

        if (! $this->confirm("Delete {$transactionCount} transaction(s) and {$balanceCount} balance(s) across {$accountIds->count()} non-connected account(s) of '{$user->email}'?")) {
            $this->info('Deletion cancelled.');

            return self::SUCCESS;
        }

        Transaction::query()->whereIn('account_id', $accountIds)->forceDelete();
        AccountBalance::query()->whereIn('account_id', $accountIds)->delete();

        $this->info("Deleted {$transactionCount} transaction(s) and {$balanceCount} balance(s) for '{$user->email}'.");

        return self::SUCCESS;
    }
}
