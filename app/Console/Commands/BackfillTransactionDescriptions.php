<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\TransactionDescriptionFormatter;
use Illuminate\Console\Command;

class BackfillTransactionDescriptions extends Command
{
    protected $signature = 'banking:backfill-descriptions
        {--user= : Filter by user email address}
        {--dry-run : Preview what would be updated without making changes}';

    protected $description = 'Re-apply the bank description formatters to already-imported transactions that still carry a raw remittance tag';

    public function handle(TransactionDescriptionFormatter $formatter): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $userEmail = $this->option('user');

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $query = Transaction::query()
            ->with('account.bank')
            ->where('description', 'like', '/TXT/%');

        if ($userEmail) {
            $user = User::query()->where('email', $userEmail)->first();

            if (! $user) {
                $this->error("User with email '{$userEmail}' not found.");

                return self::FAILURE;
            }

            $query->where('user_id', $user->id);
        }

        $updated = 0;

        $query->chunkById(500, function ($transactions) use ($formatter, $isDryRun, &$updated): void {
            foreach ($transactions as $transaction) {
                $formatted = $formatter->format($transaction->description, $transaction->account?->bank?->name);

                if ($formatted['description'] === $transaction->description) {
                    continue;
                }

                if (! $isDryRun) {
                    // Quietly: only the wording changes, so there is nothing for
                    // the transaction listeners to react to.
                    $transaction->updateQuietly([
                        'description' => $formatted['description'],
                        'original_description' => $transaction->original_description ?? $formatted['original_description'],
                    ]);
                }

                $updated++;
            }
        });

        $verb = $isDryRun ? 'would be reformatted' : 'reformatted';
        $this->info("{$updated} transaction(s) {$verb}.");

        return self::SUCCESS;
    }
}
