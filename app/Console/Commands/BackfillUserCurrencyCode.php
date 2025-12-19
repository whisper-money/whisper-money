<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class BackfillUserCurrencyCode extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:backfill-user-currency-code';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill currency_code for users based on their first account';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting currency code backfill...');

        // Get all users without a currency_code
        $users = User::whereNull('currency_code')
            ->has('accounts')
            ->with(['accounts' => function ($query) {
                $query->orderBy('created_at', 'asc')->limit(1);
            }])
            ->get();

        if ($users->isEmpty()) {
            $this->info('No users found that need currency code backfill.');
            return Command::SUCCESS;
        }

        $this->info("Found {$users->count()} users to update.");

        $bar = $this->output->createProgressBar($users->count());
        $bar->start();

        $updated = 0;

        foreach ($users as $user) {
            $firstAccount = $user->accounts->first();
            
            if ($firstAccount && $firstAccount->currency_code) {
                $user->update(['currency_code' => $firstAccount->currency_code]);
                $updated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->info("Successfully updated {$updated} users with currency codes.");

        return Command::SUCCESS;
    }
}
