<?php

namespace App\Console\Commands;

use App\Enums\BankingProvider;
use App\Mail\BankOutageEmail;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Run by hand once a bank-side outage is confirmed: it tells everyone connected
 * to that bank that their transactions and balances are stuck because the bank
 * stopped answering, and that we are on it.
 *
 * Connections are matched on `aspsp_name` (+ optional country) because that is
 * the only stable identifier for an Enable Banking bank: `banking_connections`
 * has no bank_id, and the `banks` table is per-user, so a bank UUID means
 * nothing outside the one account it belongs to.
 */
class NotifyBankOutageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'banking:notify-outage
                            {aspsp : The bank name as stored on the connection, e.g. "Openbank"}
                            {--country= : ISO country code, to disambiguate banks sharing a name (e.g. ES)}
                            {--dry-run : List the recipients without sending anything}
                            {--force : Skip the confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email the users whose Enable Banking connections are stuck on a bank-side outage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $aspsp = (string) $this->argument('aspsp');
        $country = $this->option('country');

        $matchesOutage = fn (Builder $query) => $query
            ->where('provider', BankingProvider::EnableBanking)
            ->where('aspsp_name', $aspsp)
            ->when($country, fn (Builder $query) => $query->where('aspsp_country', $country));

        // Display the bank exactly as it is stored, so a lowercase argument does
        // not reach the users' inbox as one.
        $bankName = BankingConnection::query()->tap($matchesOutage)->value('aspsp_name') ?? $aspsp;
        $label = $country ? "{$bankName} ({$country})" : $bankName;

        // Soft-deleted users and connections are excluded by their default
        // scopes; whereHas collapses a user's several connections into one row.
        $users = User::query()
            ->whereHas('bankingConnections', $matchesOutage)
            ->withCount(['bankingConnections as affected_connections_count' => $matchesOutage])
            ->orderBy('email')
            ->get()
            ->filter->canReceiveEmails();

        if ($users->isEmpty()) {
            $this->info("No users to notify: no live Enable Banking connection to {$label}.");

            return self::SUCCESS;
        }

        $this->renderTable($users);
        $this->info("{$users->count()} user(s) to notify about the {$label} outage.");

        if ($this->option('dry-run')) {
            $this->info('[dry-run] No emails sent.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Send the {$label} outage notice to {$users->count()} user(s)?", false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            Mail::to($user)->send(new BankOutageEmail($user, $bankName));
        }

        $this->info("Queued {$users->count()} outage notice(s) to the 'emails' queue.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function renderTable(Collection $users): void
    {
        $this->table(['Email', 'Connections'], $users->map(fn (User $user) => [
            $user->email,
            $user->affected_connections_count,
        ])->all());
    }
}
