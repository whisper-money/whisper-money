<?php

use App\Enums\TransactionSource;
use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Mail\BankTransactionsSyncedEmail;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('does not report the parts of a split as transactions the bank just sent', function () {
    Mail::fake();

    $user = User::factory()->create(['timezone' => null]);
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'bank_transactions_email_cutoff_at' => null,
    ]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    $bankTransaction = fn (array $attributes = []) => Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'source' => TransactionSource::EnableBanking,
        ...$attributes,
    ]);

    // One transaction the bank really did send, plus a split the user made
    // themselves: an original (soft-deleted) and its two parts, which inherit
    // the bank source and get a fresh created_at.
    $bankTransaction();
    $original = $bankTransaction(['amount' => -5000]);
    $bankTransaction(['amount' => -3000, 'split_parent_id' => $original->id]);
    $bankTransaction(['amount' => -2000, 'split_parent_id' => $original->id]);
    $original->delete();

    (new SendDailyBankTransactionsSyncedEmailJob($user, now()->toDateString()))->handle();

    Mail::assertQueued(
        BankTransactionsSyncedEmail::class,
        fn (BankTransactionsSyncedEmail $mail) => $mail->totalTransactions === 1,
    );
});
