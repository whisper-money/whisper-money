<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Services\Cache\DataVersion;
use App\Services\LoanAmortizationService;
use Illuminate\Console\Command;

class GenerateMonthlyLoanBalances extends Command
{
    protected $signature = 'loans:generate-balances';

    protected $description = 'Generate monthly balance entries for loan accounts with amortization details';

    public function __construct(protected LoanAmortizationService $amortizationService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // A sweep over every user: one data-version bump per user at the end,
        // not one per row it writes.
        return DataVersion::deferBumps(fn (): int => $this->generateBalances());
    }

    private function generateBalances(): int
    {
        $this->info('Generating monthly loan balances...');

        $accounts = Account::query()
            ->where('type', AccountType::Loan)
            ->whereHas('loanDetail')
            ->with('loanDetail')
            ->get();

        $generatedCount = 0;
        $skippedCount = 0;

        $balanceDate = now()->startOfMonth()->toDateString();

        foreach ($accounts as $account) {
            $loanDetail = $account->loanDetail;

            $existingBalance = AccountBalance::query()
                ->where('account_id', $account->id)
                ->where('balance_date', $balanceDate)
                ->exists();

            if ($existingBalance) {
                $skippedCount++;

                continue;
            }

            $projectedBalance = $this->amortizationService->getBalanceAtDate(
                $loanDetail,
                now(),
            );

            AccountBalance::create([
                'account_id' => $account->id,
                'balance_date' => $balanceDate,
                'balance' => $projectedBalance,
            ]);

            $generatedCount++;
            $this->info("Generated balance for: {$account->name} ({$projectedBalance} cents)");
        }

        $this->info("Generated {$generatedCount} balance entries, skipped {$skippedCount} (already exist)");

        return Command::SUCCESS;
    }
}
