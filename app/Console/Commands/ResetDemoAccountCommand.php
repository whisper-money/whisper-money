<?php

namespace App\Console\Commands;

use App\Actions\CreateDefaultCategories;
use App\Enums\AccountType;
use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
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
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ResetDemoAccountCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Reset the demo account with fresh data';

    private const MIN_BALANCE_GROWTH_PERCENTAGE = 0.05;

    public function __construct(
        private DemoTransactionsProvider $transactionsProvider,
        private DemoLabelsProvider $labelsProvider,
        private DemoAutomationRulesProvider $rulesProvider,
        private BudgetPeriodService $budgetPeriodService,
        private BudgetTransactionService $budgetTransactionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $demoEmail = config('app.demo.email');
        $demoPassword = config('app.demo.password');

        if (! $demoEmail || ! $demoPassword) {
            $this->error('Demo configuration not set. Please set DEMO_EMAIL and DEMO_PASSWORD in .env');

            return self::FAILURE;
        }

        $this->info("Resetting demo account: {$demoEmail}");

        $user = $this->findOrCreateDemoUser($demoEmail, $demoPassword);

        $this->deleteExistingData($user);

        $this->createCategories($user);

        $labels = $this->createLabels($user);

        $this->createAccountsWithTransactions($user, $labels);

        $this->createAutomationRules($user, $labels);

        $this->createBudgets($user);

        $this->assignTransactionsToBudgets($user);

        $this->createSubscription($user);

        $this->info('✓ Demo account reset successfully!');

        return self::SUCCESS;
    }

    private function findOrCreateDemoUser(string $email, string $password): User
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->email_verified_at ??= now();
            $user->save();

            return $user;
        }

        $user = new User([
            'email' => $email,
            'name' => 'Demo User',
            'password' => $password,
            'onboarded_at' => now(),
            'currency_code' => 'USD',
        ]);
        $user->email_verified_at = now();
        $user->save();

        return $user;
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
        $labelsConfig = $this->labelsProvider->getLabels();
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
        $bbvaBank = Bank::query()->whereNull('user_id')->where('name', 'BBVA')->first()
            ?? Bank::factory()->create(['user_id' => null]);
        $ingBank = Bank::query()->whereNull('user_id')->where('name', 'ING Direct')->first()
            ?? Bank::factory()->create(['user_id' => null]);
        $indexaCapitalBank = Bank::query()->whereNull('user_id')->where('name', 'Indexa Capital')->first()
            ?? Bank::factory()->create(['user_id' => null]);
        $binanceBank = Bank::query()->whereNull('user_id')->where('name', 'Binance')->first()
            ?? Bank::factory()->create(['user_id' => null]);
        $categories = $user->categories()->get()->keyBy('name');

        $accounts = [
            [
                'name' => 'Primary Checking',
                'type' => AccountType::Checking,
                'current_balance' => $this->generateRealisticBalance(2000000, 3500000),
                'monthly_variance' => 150000,
                'bank_account_id' => $bbvaBank->id,
            ],
            [
                'name' => 'Joint Checking',
                'type' => AccountType::Checking,
                'current_balance' => $this->generateRealisticBalance(500000, 1200000),
                'monthly_variance' => 80000,
                'bank_account_id' => $bbvaBank->id,
            ],
            [
                'name' => 'Emergency Fund',
                'type' => AccountType::Savings,
                'current_balance' => $this->generateRealisticBalance(1200000, 1800000),
                'monthly_variance' => 25000,
                'bank_account_id' => $ingBank->id,
            ],
            [
                'name' => '401(k) Retirement',
                'type' => AccountType::Retirement,
                'current_balance' => $this->generateRealisticBalance(8500000, 12500000),
                'monthly_variance' => 350000,
                'bank_account_id' => $indexaCapitalBank->id,
            ],
            [
                'name' => 'Brokerage Account',
                'type' => AccountType::Investment,
                'current_balance' => $this->generateRealisticBalance(1500000, 3500000),
                'monthly_variance' => 200000,
                'bank_account_id' => $indexaCapitalBank->id,
            ],
            [
                'name' => 'Cryptos',
                'type' => AccountType::Investment,
                'current_balance' => $this->generateRealisticBalance(1500000, 4500000),
                'monthly_variance' => 100000,
                'bank_account_id' => $binanceBank->id,
            ],
        ];

        $createdAccounts = [];
        $transactionAccounts = [];

        foreach ($accounts as $accountData) {
            $account = $user->accounts()->create([
                'name' => $accountData['name'],
                'name_iv' => null,
                'bank_id' => $accountData['bank_account_id'],
                'currency_code' => 'USD',
                'type' => $accountData['type'],
            ]);

            $this->createBalanceHistory($account, $accountData['current_balance'], $accountData['monthly_variance'], $accountData['type']);

            $createdAccounts[] = $account;

            if ($this->accountTypeHasTransactions($accountData['type'])) {
                $transactionAccounts[] = $account;
            }
        }

        $totalTransactions = $this->createMixedTransactions($transactionAccounts, $categories, $labels);

        $this->info('  Created '.count($createdAccounts)." accounts with {$totalTransactions} transactions and 12 months of balances");
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
     * @param  array<int, Account>  $accounts
     * @param  Collection<string, Category>  $categories
     * @param  array<int, array{label: Label, assignment_percentage: int}>  $labels
     */
    private function createMixedTransactions(array $accounts, Collection $categories, array $labels): int
    {
        if (empty($accounts)) {
            return 0;
        }

        $allTransactions = $this->transactionsProvider->getTransactions();
        $count = 0;

        foreach ($allTransactions as $transactionData) {
            $categoryName = $transactionData['category_name'];
            unset($transactionData['category_name']);

            $category = $categories->get($categoryName);

            if (! $category) {
                continue;
            }

            $account = $accounts[array_rand($accounts)];

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
     * @param  array<int, array{label: Label, assignment_percentage: int}>  $labels
     */
    private function createAutomationRules(User $user, array $labels): void
    {
        $rules = $this->rulesProvider->getRules();

        foreach ($rules as $ruleData) {
            $category = null;
            if ($ruleData['category_name']) {
                $category = $user->categories()->where('name', $ruleData['category_name'])->first();
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

    private function accountTypeHasTransactions(AccountType $type): bool
    {
        return $type !== AccountType::Investment && $type !== AccountType::Retirement;
    }

    private function createBudgets(User $user): void
    {
        $categories = $user->categories()->get()->keyBy('name');

        $groceriesCategory = $categories->get('Groceries');
        $diningCategory = $categories->get('Cafes, restaurants, bars');

        if (! $groceriesCategory || ! $diningCategory) {
            $this->warn('  Skipping budget creation - required categories not found');

            return;
        }

        $budget1 = Budget::create([
            'user_id' => $user->id,
            'name' => 'Monthly Groceries',
            'period_type' => BudgetPeriodType::Monthly,
            'period_start_day' => 1,
            'category_id' => $groceriesCategory->id,
            'rollover_type' => RolloverType::CarryOver,
        ]);

        $this->generateHistoricalPeriods($budget1, 320000);

        $budget2 = Budget::create([
            'user_id' => $user->id,
            'name' => 'Weekly Dining Out',
            'period_type' => BudgetPeriodType::Weekly,
            'period_start_day' => 0,
            'category_id' => $diningCategory->id,
            'rollover_type' => RolloverType::Reset,
        ]);

        $this->generateHistoricalPeriods($budget2, 20000);

        $groceriesPeriodCount = $budget1->periods()->count();
        $diningPeriodCount = $budget2->periods()->count();
        $groceriesCurrentPeriod = $budget1->getCurrentPeriod();
        $diningCurrentPeriod = $budget2->getCurrentPeriod();

        $this->info('  Created 2 budgets with historical periods');
        $this->info("    - Monthly Groceries: {$groceriesPeriodCount} periods".($groceriesCurrentPeriod ? ' (has current period)' : ' (no current period)'));
        $this->info("    - Weekly Dining Out: {$diningPeriodCount} periods".($diningCurrentPeriod ? ' (has current period)' : ' (no current period)'));
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
        $transactions = $user->transactions()->get();
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

    private function createSubscription(User $user): void
    {
        $user->subscriptions()->delete();

        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_demo_free_forever',
            'stripe_status' => 'active',
            'stripe_price' => 'price_demo_free',
        ]);

        $this->info('  Created demo subscription');
    }
}
