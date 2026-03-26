<?php

namespace App\Console\Commands;

use App\Models\RealEstateDetail;
use Illuminate\Console\Command;

class ApplyRealEstateRevaluationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'real-estate:apply-revaluation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply monthly revaluation to real estate accounts based on their annual revaluation percentage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $details = RealEstateDetail::query()
            ->whereNotNull('revaluation_percentage')
            ->where('revaluation_percentage', '!=', 0)
            ->with('account')
            ->get();

        $applied = 0;
        $skipped = 0;

        foreach ($details as $detail) {
            $account = $detail->account;

            if (! $account) {
                $skipped++;

                continue;
            }

            $latestBalance = $account->balances()
                ->orderByDesc('balance_date')
                ->first();

            if (! $latestBalance) {
                $skipped++;

                continue;
            }

            $monthlyRate = (float) $detail->revaluation_percentage / 12 / 100;
            $newBalance = (int) round($latestBalance->balance * (1 + $monthlyRate));

            $account->balances()->updateOrCreate(
                ['balance_date' => now()->toDateString()],
                ['balance' => $newBalance],
            );

            $applied++;
        }

        $this->info("Revaluation applied to {$applied} account(s), skipped {$skipped}.");

        return self::SUCCESS;
    }
}
