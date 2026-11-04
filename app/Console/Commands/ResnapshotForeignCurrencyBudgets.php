<?php

namespace App\Console\Commands;

use App\Models\BudgetTransaction;
use App\Models\User;
use App\Services\BudgetTransactionService;
use App\Services\CurrencyOptions;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @phpstan-type ChangeTally array{currency: string, rows: int, periods: array<string, true>, delta: int}
 */
class ResnapshotForeignCurrencyBudgets extends Command
{
    protected $signature = 'budgets:resnapshot-foreign-currency
        {--user= : Only repair the budget rows of the user with this id}
        {--force : Write the changes (without it, only report what would change)}';

    protected $description = 'Convert to the user\'s currency the budget rows of transactions booked in another currency, which used to be stored as booked, so budgets add up like the cashflow';

    public function handle(BudgetTransactionService $service): int
    {
        $isForced = (bool) $this->option('force');
        $userId = $this->option('user');

        if ($userId && (! Str::isUuid($userId) || ! User::query()->whereKey($userId)->exists())) {
            $this->error("User with id '{$userId}' not found.");

            return self::FAILURE;
        }

        if (! $isForced) {
            $this->warn('DRY RUN — no changes will be saved. Pass --force to write them.');
        }

        $changesByUser = [];

        // Silent: no budget email is sent and no limit alert flag is touched.
        $service->eachStaleSnapshot(
            $service->foreignCurrencySnapshots(fn ($query) => $query->when($userId, fn ($q) => $q->where('transactions.user_id', $userId))),
            function (BudgetTransaction $row, int $amount) use ($isForced, &$changesByUser): void {
                $this->record($changesByUser, $row, $amount);

                $this->line("Budget period {$row->budget_period_id}, transaction {$row->transaction_id} ({$row->transaction->transaction_date->toDateString()}): {$row->amount} → {$amount} (minor units)", verbosity: OutputInterface::VERBOSITY_VERBOSE);

                if ($isForced) {
                    $row->update(['amount' => $amount]);
                }
            },
        );

        $this->report(collect($changesByUser), $isForced);

        return self::SUCCESS;
    }

    /**
     * Add a stale row, about to hold $amount, to the per-user tally.
     *
     * @param  array<string, ChangeTally>  $changesByUser  keyed by user id
     */
    private function record(array &$changesByUser, BudgetTransaction $row, int $amount): void
    {
        $user = $row->transaction->user;

        $changesByUser[$user->id] ??= ['currency' => $user->currency_code, 'rows' => 0, 'periods' => [], 'delta' => 0];
        $changesByUser[$user->id]['rows']++;
        $changesByUser[$user->id]['periods'][$row->budget_period_id] = true;
        $changesByUser[$user->id]['delta'] += $amount - $row->amount;
    }

    /**
     * @param  Collection<string, ChangeTally>  $changes  keyed by user id
     */
    private function report(Collection $changes, bool $isForced): void
    {
        $verb = $isForced ? 'were' : 'would be';

        if ($changes->isEmpty()) {
            $this->info("0 budget row(s) {$verb} rewritten: every foreign-currency row already holds its converted amount.");

            return;
        }

        $this->table(
            ['User', 'Currency', 'Rows', 'Periods', 'Spending delta'],
            $changes->map(fn (array $change, string $userId): array => [
                $userId,
                strtoupper($change['currency']),
                $change['rows'],
                count($change['periods']),
                $this->formatAmount($change['delta'], $change['currency']),
            ])->values()->all(),
        );

        $rows = $changes->sum('rows');
        $periods = $changes->sum(fn (array $change): int => count($change['periods']));
        $deltaByCurrency = $changes
            ->groupBy(fn (array $change): string => strtoupper($change['currency']))
            ->map(fn ($group, string $currency): string => $this->formatAmount($group->sum('delta'), $currency))
            ->implode(', ');

        $this->info("{$rows} budget row(s) across {$changes->count()} user(s) and {$periods} budget period(s) {$verb} rewritten. Spending delta: {$deltaByCurrency}.");
    }

    /**
     * A signed amount in minor units, in major units with the currency's own
     * decimals, e.g. "-18.43 EUR" or "+1500 COP".
     */
    private function formatAmount(int $amount, string $currency): string
    {
        return ($amount > 0 ? '+' : '').number_format(
            Money::toMajor($amount, $currency),
            app(CurrencyOptions::class)->decimals($currency),
            '.',
            '',
        ).' '.strtoupper($currency);
    }
}
