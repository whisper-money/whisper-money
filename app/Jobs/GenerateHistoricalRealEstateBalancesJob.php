<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\RealEstateBalanceGeneratorService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateHistoricalRealEstateBalancesJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Account $account,
        public int $purchasePrice,
        public Carbon $purchaseDate,
        public int $currentValue,
        public Carbon $from,
        public Carbon $to,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(RealEstateBalanceGeneratorService $service): void
    {
        $service->generateHistoricalBalances(
            $this->account,
            $this->purchasePrice,
            $this->purchaseDate,
            $this->currentValue,
            $this->from,
            $this->to,
        );
    }
}
