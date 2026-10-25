<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\LoanBalanceGeneratorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateHistoricalLoanBalancesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public Account $account,
        public int $originalAmount,
        public Carbon $startDate,
        public int $currentBalance,
        public Carbon $from,
        public Carbon $to,
    ) {}

    public function handle(LoanBalanceGeneratorService $service): void
    {
        $service->generateHistoricalBalances(
            $this->account,
            $this->originalAmount,
            $this->startDate,
            $this->currentBalance,
            $this->from,
            $this->to,
        );
    }
}
