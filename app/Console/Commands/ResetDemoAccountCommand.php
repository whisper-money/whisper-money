<?php

namespace App\Console\Commands;

use App\Actions\CreateDefaultCategories;
use App\Enums\AccountType;
use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;
use App\Services\BudgetPeriodService;
use App\Services\BudgetTransactionService;
use App\Services\Demo\DemoAutomationRulesProvider;
use App\Services\Demo\DemoLabelsProvider;
use App\Services\Demo\DemoTransactionsProvider;
use App\Services\Demo\PressDataset;
use App\Services\LoanBalanceGeneratorService;
use App\Services\RealEstateBalanceGeneratorService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ResetDemoAccountCommand extends Command
{
    protected $signature = 'demo:reset
        {--press : Create or reset the shared press account (config app.press.*) on Spanish data}
        {--email= : Create or reset this account instead of the configured demo user}
        {--password= : Password for --email}
        {--imported : Mark one account\'s transactions as bank-imported, so the read-only protections can be demonstrated}';

    protected $description = 'Reset the demo or press account with fresh data';

    private const MIN_BALANCE_GROWTH_PERCENTAGE = 0.05;

    /**
     * The demo account's own seed data, in the shape every seeded account is
     * described in (PressDataset::get() is the other one).
     *
     * @var array<int, array<string, mixed>>
     */
    private const DEMO_ACCOUNTS = [
        ['name' => 'Primary Checking', 'type' => 'checking', 'bank' => 'BBVA', 'balance_min' => 2000000, 'balance_max' => 3500000, 'monthly_variance' => 150000],
        ['name' => 'Joint Checking', 'type' => 'checking', 'bank' => 'BBVA', 'balance_min' => 500000, 'balance_max' => 1200000, 'monthly_variance' => 80000],
        ['name' => 'Emergency Fund', 'type' => 'savings', 'bank' => 'ING Direct', 'balance_min' => 1200000, 'balance_max' => 1800000, 'monthly_variance' => 25000],
        ['name' => '401(k) Retirement', 'type' => 'retirement', 'bank' => 'Indexa Capital', 'balance_min' => 8500000, 'balance_max' => 12500000, 'monthly_variance' => 350000],
        ['name' => 'Brokerage Account', 'type' => 'investment', 'bank' => 'Indexa Capital', 'balance_min' => 1500000, 'balance_max' => 3500000, 'monthly_variance' => 200000],
        ['name' => 'Cryptos', 'type' => 'investment', 'bank' => 'Binance', 'balance_min' => 1500000, 'balance_max' => 4500000, 'monthly_variance' => 100000],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const DEMO_BUDGETS = [
        ['name' => 'Monthly Groceries', 'category_name' => 'Groceries', 'period_type' => 'monthly', 'period_start_day' => 1, 'rollover_type' => 'carry_over', 'allocated_amount' => 320000],
        ['name' => 'Weekly Dining Out', 'category_name' => 'Cafes, restaurants, bars', 'period_type' => 'weekly', 'period_start_day' => 0, 'rollover_type' => 'reset', 'allocated_amount' => 20000],
    ];

    /**
     * The resolved seed data for this run.
     *
     * @var array<string, mixed>
     */
    private array $dataset = [];

    public function __construct(
        private DemoTransactionsProvider $transactionsProvider,
        private DemoLabelsProvider $labelsProvider,
        private DemoAutomationRulesProvider $rulesProvider,
        private BudgetPeriodService $budgetPeriodService,
        private BudgetTransactionService $budgetTransactionService,
        private LoanBalanceGeneratorService $loanBalances,
        private RealEstateBalanceGeneratorService $realEstateBalances,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->seedsPublicDemo() && ! config('app.demo.enabled')) {
            $this->info('Demo account is not enabled');

            return self::SUCCESS;
        }

        $credentials = $this->credentials();

        if ($credentials === null) {
            return $this->reportMissingCredentials();
        }

        [$email, $password] = $credentials;

        $this->dataset = $this->option('press') ? PressDataset::get() : $this->demoDataset();

        $this->info("Resetting seeded account: {$email}");

        $user = $this->findOrCreateDemoUser($email, $password);

        $this->deleteExistingData($user);

        $this->silenceNotifications($user);

        $this->createCategories($user);

        $labels = $this->createLabels($user);

        $this->createAccountsWithTransactions($user, $labels);

        $this->createAutomationRules($user, $labels);

        $this->createBudgets($user);

        $this->assignTransactionsToBudgets($user);

        $this->createSubscription($user);

        if ($this->option('imported')) {
            $this->markTransactionsAsImported($user);
        }

        $this->info('✓ Account reset successfully!');

        return self::SUCCESS;
    }

    /**
     * Whether this run targets the public demo account, as opposed to the press
     * account or a named one (e.g. an app-store reviewer). Only the public demo
     * answers to `DEMO_ENABLED`.
     */
    private function seedsPublicDemo(): bool
    {
        return ! $this->option('press') && (string) $this->option('email') === '';
    }

    /**
     * The account to seed: the press account, an explicitly named one (e.g. an
     * app-store reviewer) or the public demo. Null when it cannot be resolved.
     *
     * @return array{0: string, 1: string}|null
     */
    private function credentials(): ?array
    {
        if ($this->option('press')) {
            $email = (string) config('app.press.email');
            $password = (string) config('app.press.password');

            return $email !== '' && $password !== '' ? [$email, $password] : null;
        }

        $explicitEmail = (string) $this->option('email');

        if ($explicitEmail !== '') {
            $password = (string) $this->option('password');

            return $password !== '' ? [$explicitEmail, $password] : null;
        }

        $email = (string) config('app.demo.email');
        $password = (string) config('app.demo.password');

        return $email !== '' && $password !== '' ? [$email, $password] : null;
    }

    private function reportMissingCredentials(): int
    {
        if ($this->option('press')) {
            $this->error('Press configuration not set. Please set PRESS_EMAIL and PRESS_PASSWORD in .env');
        } elseif ((string) $this->option('email') !== '') {
            $this->error('Pass --password together with --email.');
        } else {
            $this->error('Demo configuration not set. Please set DEMO_EMAIL and DEMO_PASSWORD in .env');
        }

        return self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function demoDataset(): array
    {
        return [
            'name' => 'Demo User',
            'locale' => null,
            'currency' => 'USD',
            'subscription_prefix' => 'sub_demo_',
            'accounts' => self::DEMO_ACCOUNTS,
            'labels' => $this->labelsProvider->getLabels(),
            'rules' => $this->rulesProvider->getRules(),
            'budgets' => self::DEMO_BUDGETS,
            'transaction_templates' => null,
        ];
    }

    /**
     * Find or create the account, then re-apply the profile the dataset asks
     * for. The row itself is never deleted: an OAuth connection (a journalist's
     * Claude Desktop) hangs off the user id, and recreating it would break every
     * live connection in silence.
     */
    private function findOrCreateDemoUser(string $email, string $password): User
    {
        $user = User::where('email', $email)->first() ?? new User(['email' => $email]);

        $user->fill([
            'name' => $this->dataset['name'],
            'password' => $password,
            'onboarded_at' => $user->onboarded_at ?? now(),
            'currency_code' => $this->dataset['currency'],
        ]);

        if ($this->dataset['locale'] !== null) {
            $user->locale = $this->dataset['locale'];
        }

        $user->email_verified_at ??= now();
        $user->save();

        return $user;
    }

    /**
     * A seeded account is a fixture nobody reads mail for, so every e-mail
     * notification it could trigger is switched off.
     */
    private function silenceNotifications(User $user): void
    {
        $user->setting()->updateOrCreate([], [
            'notify_on_bank_transactions_synced' => false,
            'notify_on_inactive_no_bank' => false,
            'budget_notify_on_new_transaction' => false,
            'budget_notify_on_close_to_limit' => false,
            'budget_notify_on_over_limit' => false,
        ]);

        $this->info('  Silenced e-mail notifications');
    }

    private function deleteExistingData(User $user): void
    {
        $user->transactions()->forceDelete();
        $user->accounts()->forceDelete();
        $user->labels()->forceDelete();
        $user->automationRules()->forceDelete();
        $user->categories()->forceDelete();
        $user->budgets()->forceDelete();
        $user->encryptedMessage()->delete();

        $this->info('  Deleted existing data');
    }

    private function createCategories(User $user): void
    {
        (new CreateDefaultCategories)->handle($user);
        $this->info('  Created default categories');
    }

    /**
     * @return array<int, array{label: Label, assignment_percentage: int}>
     */
    private function createLabels(User $user): array
    {
        $labelsConfig = $this->dataset['labels'];
        $labels = [];

        foreach ($labelsConfig as $labelConfig) {
            $label = $user->labels()->create([
                'name' => $labelConfig['name'],
                'color' => $labelConfig['color'],
            ]);
            $labels[] = [
                'label' => $label,
                'assignment_percentage' => $labelConfig['assignment_percentage'],
            ];
        }

        $this->info('  Created '.count($labels).' labels');

        return $labels;
    }

    /**
     * @param  array<int, array{label: Label, assignment_percentage: int}>  $labels
     */
    private function createAccountsWithTransactions(User $user, array $labels): void
    {
        $categories = $user->categories()->get()->keyBy('name');
        $created = [];
        $transactionAccounts = [];

        foreach ($this->dataset['accounts'] as $accountData) {
            $type = $accountData['type'] instanceof AccountType
                ? $accountData['type']
                : AccountType::from($accountData['type']);

            $account = $user->accounts()->create([
                'name' => $accountData['name'],
                'name_iv' => null,
                'bank_id' => $this->bankId($accountData['bank'] ?? null),
                'currency_code' => $this->dataset['currency'],
                'type' => $type,
            ]);

            $this->createAccountBalances($account, $accountData, $type);

            $created[] = $account;

            if ($type->hasTransactionLedger()) {
                $transactionAccounts[$account->name] = $account;
            }
        }

        $totalTransactions = $this->createMixedTransactions($transactionAccounts, $categories, $labels);

        $this->info('  Created '.count($created)." accounts with {$totalTransactions} transactions and 12 months of balances");
    }

    /**
     * The shared bank record a seeded account hangs off, or null for the types
     * that have no bank (a flat). Falls back to a factory-made bank so seeding
     * still works on a database whose institution catalogue was never synced.
     */
    private function bankId(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $bank = Bank::query()->whereNull('user_id')->where('name', $name)->first()
            ?? Bank::factory()->create(['user_id' => null, 'name' => $name]);

        return $bank->id;
    }

    /**
     * Balance history for one account. Loans and property have their own curves
     * — amortization and revaluation — so they go through the services that own
     * that maths instead of the generic random walk.
     *
     * @param  array<string, mixed>  $accountData
     */
    private function createAccountBalances(Account $account, array $accountData, AccountType $type): void
    {
        $currentBalance = $this->generateRealisticBalance($accountData['balance_min'], $accountData['balance_max']);

        if (isset($accountData['loan'])) {
            $loan = $accountData['loan'];
            $startDate = now()->subYears($loan['start_years_ago'])->startOfMonth();

            $account->loanDetail()->create([
                'original_amount' => $loan['original_amount'],
                'annual_interest_rate' => $loan['annual_interest_rate'],
                'loan_term_months' => $loan['loan_term_months'],
                'start_date' => $startDate,
            ]);

            $this->loanBalances->generateHistoricalBalances(
                $account,
                $loan['original_amount'],
                $startDate,
                $currentBalance,
            );

            return;
        }

        if (isset($accountData['real_estate'])) {
            $property = $accountData['real_estate'];
            $purchaseDate = now()->subYears($property['purchase_years_ago'])->startOfMonth();

            $account->realEstateDetail()->create([
                'property_type' => $property['property_type'],
                'address' => $property['address'],
                'purchase_price' => $property['purchase_price'],
                'purchase_date' => $purchaseDate,
                'area_value' => $property['area_value'],
                'area_unit' => $property['area_unit'],
                'revaluation_percentage' => $property['revaluation_percentage'],
            ]);

            $this->realEstateBalances->generateHistoricalBalances(
                $account,
                $property['purchase_price'],
                $purchaseDate,
                $currentBalance,
            );

            return;
        }

        $this->createBalanceHistory($account, $currentBalance, $accountData['monthly_variance'], $type);
    }

    private function generateRealisticBalance(int $min, int $max): int
    {
        $base = rand($min, $max);
        $cents = rand(0, 99);

        return (int) (floor($base / 100) * 100 + $cents);
    }

    private function createBalanceHistory(Account $account, int $currentBalance, int $monthlyVariance, AccountType $type): void
    {
        $targetFirstMonthBalance = (int) ($currentBalance / (1 + self::MIN_BALANCE_GROWTH_PERCENTAGE));
        $balance = $currentBalance;
        $balances = [];

        for ($i = 0; $i <= 12; $i++) {
            $date = now()->subMonthsNoOverflow($i)->endOfMonth();

            if ($i === 0) {
                $date = now();
            }

            $balances[] = [
                'date' => $date,
                'balance' => $balance,
            ];

            if ($i < 12) {
                $change = rand(-$monthlyVariance, $monthlyVariance);
                $balance = max(10000, $balance - $change);
                $balance = $this->generateRealisticBalance($balance - 5000, $balance + 5000);
            }
        }

        $firstMonthBalance = $balances[12]['balance'];
        $reductionNeeded = $firstMonthBalance - $targetFirstMonthBalance;

        if ($reductionNeeded > 0) {
            $reductionPerMonth = ($reductionNeeded + 100) / 12;

            for ($i = 0; $i <= 12; $i++) {
                $monthIndex = $i;
                $reduction = (int) ($reductionPerMonth * $monthIndex);
                $balances[$i]['balance'] = max(10000, $balances[$i]['balance'] - $reduction);
            }
        }

        $trackInvestedAmount = $type->supportsInvestedAmount();

        foreach ($balances as $i => $balanceData) {
            $attributes = [
                'balance_date' => $balanceData['date']->format('Y-m-d'),
                'balance' => $balanceData['balance'],
            ];

            if ($trackInvestedAmount) {
                $gainPercentage = 0.05 + (0.20 - 0.05) * ($i / 12);
                $attributes['invested_amount'] = (int) ($balanceData['balance'] / (1 + $gainPercentage));
            }

            $account->balances()->create($attributes);
        }
    }

    /**
     * @param  array<string, Account>  $accounts  keyed by account name
     * @param  Collection<string, Category>  $categories
     * @param  array<int, array{label: Label, assignment_percentage: int}>  $labels
     */
    private function createMixedTransactions(array $accounts, Collection $categories, array $labels): int
    {
        if ($accounts === []) {
            return 0;
        }

        $allTransactions = $this->transactionsProvider->getTransactions(
            $this->dataset['transaction_templates'],
            $this->dataset['currency'],
        );

        $accountNames = array_keys($accounts);
        $count = 0;

        foreach ($allTransactions as $transactionData) {
            $categoryName = $this->categoryName($transactionData['category_name']);
            $accountName = $transactionData['account_name'];
            unset($transactionData['category_name'], $transactionData['account_name']);

            $category = $categories->get($categoryName);

            if (! $category) {
                continue;
            }

            $account = $accounts[$accountName] ?? $accounts[$accountNames[array_rand($accountNames)]];

            $transactionData['description_iv'] = null;

            $transaction = $account->transactions()->create([
                'user_id' => $account->user_id,
                'category_id' => $category->id,
                ...$transactionData,
            ]);

            foreach ($labels as $labelConfig) {
                if (rand(1, 100) <= $labelConfig['assignment_percentage']) {
                    $transaction->labels()->attach($labelConfig['label']->id);
                }
            }

            $count++;
        }

        return $count;
    }

    /**
     * The name a canonical (English) category was seeded under for this account's
     * locale. Datasets reference categories in English so the same set works for
     * both accounts.
     */
    private function categoryName(string $canonical): string
    {
        return CreateDefaultCategories::localizedCategoryName($canonical, $this->dataset['locale'] ?? 'en');
    }

    /**
     * @param  array<int, array{label: Label, assignment_percentage: int}>  $labels
     */
    private function createAutomationRules(User $user, array $labels): void
    {
        $rules = $this->dataset['rules'];

        foreach ($rules as $ruleData) {
            $category = null;
            if ($ruleData['category_name']) {
                $category = $user->categories()
                    ->where('name', $this->categoryName($ruleData['category_name']))
                    ->first();
            }

            $rule = $user->automationRules()->create([
                'title' => $ruleData['title'],
                'priority' => $ruleData['priority'],
                'rules_json' => $ruleData['rules_json'],
                'action_category_id' => $category?->id,
                'action_note' => $ruleData['action_note'],
                'action_note_iv' => $ruleData['action_note'] ? 'demo_iv' : null,
            ]);

            if (rand(0, 1) && ! empty($labels)) {
                $randomLabel = $labels[array_rand($labels)]['label'];
                $rule->labels()->attach($randomLabel->id);
            }
        }

        $this->info('  Created '.count($rules).' automation rules');
    }

    private function createBudgets(User $user): void
    {
        $categories = $user->categories()->get()->keyBy('name');

        foreach ($this->dataset['budgets'] as $budgetData) {
            $category = $categories->get($this->categoryName($budgetData['category_name']));

            if (! $category) {
                $this->warn("  Skipping budget '{$budgetData['name']}' - category not found");

                continue;
            }

            $budget = Budget::create([
                'user_id' => $user->id,
                'name' => $budgetData['name'],
                'period_type' => BudgetPeriodType::from($budgetData['period_type']),
                'period_start_day' => $budgetData['period_start_day'],
                'rollover_type' => RolloverType::from($budgetData['rollover_type']),
                // A budget copies the user's notification defaults at creation;
                // a seeded account must not e-mail anyone when a reseed pushes a
                // period over its limit.
                'notify_on_new_transaction' => false,
                'notify_on_close_to_limit' => false,
                'notify_on_over_limit' => false,
            ]);

            // What a budget tracks lives in the pivot, not on a column: without
            // this sync nothing is ever assigned to the budget and every period
            // reads as untouched.
            $budget->categories()->sync([$category->id]);

            $this->generateHistoricalPeriods($budget, $budgetData['allocated_amount']);

            $periods = $budget->periods()->count();
            $current = $budget->getCurrentPeriod() ? ' (has current period)' : ' (no current period)';

            $this->info("    - {$budgetData['name']}: {$periods} periods{$current}");
        }

        $this->info('  Created '.count($this->dataset['budgets']).' budgets with historical periods');
    }

    private function generateHistoricalPeriods(Budget $budget, int $allocatedAmount): void
    {
        $transactionStartDate = now()->subMonths(12);
        $endDate = now()->addWeek();

        $currentDate = $transactionStartDate->copy();

        if ($budget->period_type === BudgetPeriodType::Weekly) {
            $dayOfWeek = $budget->period_start_day ?? 0;
            while ($currentDate->dayOfWeek !== $dayOfWeek) {
                $currentDate->subDay();
            }
        } elseif ($budget->period_type === BudgetPeriodType::Monthly) {
            $currentDate->startOfMonth();
            if ($budget->period_start_day) {
                $currentDate->day($budget->period_start_day);
            }
        }

        $maxIterations = 1000;
        $iteration = 0;

        while ($currentDate->lte($endDate) && $iteration < $maxIterations) {
            $period = $this->budgetPeriodService->generatePeriod($budget, $allocatedAmount, $currentDate);

            if ($period->end_date->gte($endDate)) {
                break;
            }

            $currentDate = $period->end_date->copy()->addDay();

            switch ($budget->period_type) {
                case BudgetPeriodType::Monthly:
                    if ($budget->period_start_day) {
                        $currentDate->day($budget->period_start_day);
                    } else {
                        $currentDate->startOfMonth();
                    }
                    break;
                case BudgetPeriodType::Weekly:
                    $dayOfWeek = $budget->period_start_day ?? 0;
                    while ($currentDate->dayOfWeek !== $dayOfWeek && $currentDate->lte($endDate)) {
                        $currentDate->addDay();
                    }
                    break;
                case BudgetPeriodType::Biweekly:
                    $dayOfWeek = $budget->period_start_day ?? 0;
                    while ($currentDate->dayOfWeek !== $dayOfWeek && $currentDate->lte($endDate)) {
                        $currentDate->addDay();
                    }
                    break;
            }

            $iteration++;
        }
    }

    private function assignTransactionsToBudgets(User $user): void
    {
        $transactions = $user->transactions()->with('account')->get();
        $assignedCount = 0;
        $budgetAssignments = [];

        foreach ($transactions as $transaction) {
            $this->budgetTransactionService->assignTransaction($transaction);
            $transaction->refresh();
            $budgetTransactions = $transaction->budgetTransactions()->with('budgetPeriod.budget')->get();

            if ($budgetTransactions->isNotEmpty()) {
                $assignedCount++;
                foreach ($budgetTransactions as $budgetTransaction) {
                    $budgetName = $budgetTransaction->budgetPeriod->budget->name;
                    $budgetAssignments[$budgetName] = ($budgetAssignments[$budgetName] ?? 0) + 1;
                }
            }
        }

        $this->info("  Assigned {$assignedCount} transactions to budgets");
        foreach ($budgetAssignments as $budgetName => $count) {
            $this->info("    - {$budgetName}: {$count} transactions");
        }
    }

    /**
     * Mark one account's transactions as bank-imported so a reviewer can see
     * update_transaction and delete_transaction refuse to touch synced data.
     * The account keeps no banking connection, so no sync ever runs on it.
     */
    private function markTransactionsAsImported(User $user): void
    {
        $account = $user->accounts()->whereHas('transactions')->first();

        if ($account === null) {
            return;
        }

        $count = $account->transactions()->update(['source' => TransactionSource::EnableBanking]);

        $this->info("  Marked {$count} transactions on '{$account->name}' as bank-imported");
    }

    /**
     * `subscriptions.stripe_id` is unique, so the fake id has to be derived from
     * the user: --email lets several demo accounts coexist, and a shared literal
     * would collide with whichever account was seeded first.
     *
     * The prefix is what keeps these out of the subscription metrics, so the
     * press account gets its own (`sub_press_`) rather than passing for a demo.
     */
    private function createSubscription(User $user): void
    {
        $prefix = $this->dataset['subscription_prefix'];

        $user->subscriptions()->delete();

        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => "{$prefix}{$user->id}",
            'stripe_status' => 'active',
            'stripe_price' => 'price_demo_free',
        ]);

        $this->info('  Created seeded subscription');
    }
}
