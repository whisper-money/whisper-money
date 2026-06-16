<?php

namespace App\Console\Commands;

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingProvider;
use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Throwable;

class BackfillAccountIbans extends Command
{
    protected $signature = 'banking:backfill-ibans
        {--user= : Filter by user email address}
        {--connection= : Filter by banking connection ID}
        {--dry-run : Preview what would be updated without making changes}';

    protected $description = 'Backfill missing IBAN values for Enable Banking accounts by fetching them from the API';

    public function handle(BankingProviderInterface $provider): int
    {
        $isDryRun = $this->option('dry-run');
        $userEmail = $this->option('user');
        $connectionId = $this->option('connection');

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $query = Account::query()
            ->whereNull('iban')
            ->whereNotNull('external_account_id')
            ->whereNotNull('banking_connection_id')
            ->whereHas('bankingConnection', fn ($q) => $q->where('provider', BankingProvider::EnableBanking));

        if ($connectionId) {
            $query->where('banking_connection_id', $connectionId);
        }

        if ($userEmail) {
            $user = User::query()->where('email', $userEmail)->first();

            if (! $user) {
                $this->error("User with email '{$userEmail}' not found.");

                return Command::FAILURE;
            }

            $query->where('user_id', $user->id);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('No accounts found with missing IBAN.');

            return Command::SUCCESS;
        }

        $this->info("Found {$accounts->count()} account(s) with missing IBAN.");

        $bar = $this->output->createProgressBar($accounts->count());
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $expiredSessions = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $data = $provider->getAccount($account->external_account_id);
                $iban = $data['account_id']['iban'] ?? null;

                if (! $iban) {
                    $skipped++;
                    $bar->advance();

                    continue;
                }

                if (! $isDryRun) {
                    $account->update(['iban' => $iban]);
                }

                $updated++;
            } catch (RequestException $e) {
                if ($e->response->status() === 404) {
                    $expiredSessions++;
                } else {
                    $this->newLine();
                    $this->warn("Failed for account {$account->id} ({$account->external_account_id}): {$e->getMessage()}");
                    $failed++;
                }
            } catch (Throwable $e) {
                $this->newLine();
                $this->warn("Failed for account {$account->id} ({$account->external_account_id}): {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $verb = $isDryRun ? 'would be updated' : 'updated';
        $this->info("IBAN {$verb} for {$updated} account(s). Skipped (no IBAN in API response): {$skipped}. Skipped (expired/revoked session): {$expiredSessions}. Failed: {$failed}.");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
