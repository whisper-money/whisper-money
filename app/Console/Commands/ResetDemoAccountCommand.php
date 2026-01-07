<?php

namespace App\Console\Commands;

use App\Actions\CreateDefaultCategories;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\EncryptedMessage;
use App\Models\Label;
use App\Models\User;
use App\Services\Demo\DemoAutomationRulesProvider;
use App\Services\Demo\DemoEncryptionService;
use App\Services\Demo\DemoLabelsProvider;
use App\Services\Demo\DemoTransactionsProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ResetDemoAccountCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Reset the demo account with fresh data';

    private const MIN_BALANCE_GROWTH_PERCENTAGE = 0.05;

    private string $encryptionKey;

    public function __construct(
        private DemoTransactionsProvider $transactionsProvider,
        private DemoLabelsProvider $labelsProvider,
        private DemoAutomationRulesProvider $rulesProvider,
        private DemoEncryptionService $encryptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $demoEmail = config('app.demo.email');
        $demoPassword = config('app.demo.password');
        $demoEncryptionKey = config('app.demo.encryption_key');

        if (! $demoEmail || ! $demoPassword) {
            $this->error('Demo configuration not set. Please set DEMO_EMAIL and DEMO_PASSWORD in .env');

            return self::FAILURE;
        }

        $this->info("Resetting demo account: {$demoEmail}");

        $salt = $this->encryptionService->generateSalt($demoEncryptionKey);
        $this->encryptionKey = $this->encryptionService->deriveKey($demoEncryptionKey, $salt);

        $user = $this->findOrCreateDemoUser($demoEmail, $demoPassword, $salt);

        $this->deleteExistingData($user);

        $this->createEncryptedMessage($user);

        $this->createCategories($user);

        $labels = $this->createLabels($user);

        $this->createAccountsWithTransactions($user, $labels);

        $this->createAutomationRules($user, $labels);

        $this->createSubscription($user);

        $this->info('✓ Demo account reset successfully!');

        return self::SUCCESS;
    }

    private function findOrCreateDemoUser(string $email, string $password, string $salt): User
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update(['encryption_salt' => $salt]);

            return $user;
        }

        return User::create([
            'email' => $email,
            'name' => 'Demo User',
            'password' => $password,
            'email_verified_at' => now(),
            'onboarded_at' => now(),
            'encryption_salt' => $salt,
            'currency_code' => 'USD',
        ]);
    }

    private function deleteExistingData(User $user): void
    {
        $user->transactions()->forceDelete();
        $user->accounts()->forceDelete();
        $user->labels()->forceDelete();
        $user->automationRules()->forceDelete();
        $user->categories()->forceDelete();
        $user->encryptedMessage()?->delete();

        $this->info('  Deleted existing data');
    }

    private function createEncryptedMessage(User $user): void
    {
        $testMessage = $this->encryptionService->encrypt(
            'Hello, world',
            $this->encryptionKey,
            'demo_test_message'
        );

        EncryptedMessage::create([
            'user_id' => $user->id,
            'encrypted_content' => $testMessage['encrypted'],
            'iv' => $testMessage['iv'],
        ]);

        $this->info('  Created encrypted message for key verification');
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
        $ingBank = Bank::query()->whereNull('user_id')->where('name', 'ING')->first()
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

        $totalTransactions = 0;

        foreach ($accounts as $index => $accountData) {
            $encrypted = $this->encryptionService->encrypt(
                $accountData['name'],
                $this->encryptionKey,
                "demo_account_{$index}"
            );

            $account = $user->accounts()->create([
                'name' => $encrypted['encrypted'],
                'name_iv' => $encrypted['iv'],
                'bank_id' => $accountData['bank_account_id'],
                'currency_code' => 'USD',
                'type' => $accountData['type'],
            ]);

            $this->createBalanceHistory($account, $accountData['current_balance'], $accountData['monthly_variance']);

            if ($this->accountTypeHasTransactions($accountData['type'])) {
                $transactionCount = $this->createTransactionsForAccount($account, $categories, $labels);
                $totalTransactions += $transactionCount;
            }
        }

        $this->info("  Created 5 accounts with {$totalTransactions} transactions and 12 months of balances");
    }

    private function generateRealisticBalance(int $min, int $max): int
    {
        $base = rand($min, $max);
        $cents = rand(0, 99);

        return (int) (floor($base / 100) * 100 + $cents);
    }

    private function createBalanceHistory(Account $account, int $currentBalance, int $monthlyVariance): void
    {
        $targetFirstMonthBalance = (int) ($currentBalance / (1 + self::MIN_BALANCE_GROWTH_PERCENTAGE));
        $balance = $currentBalance;
        $balances = [];

        for ($i = 0; $i <= 12; $i++) {
            $date = now()->subMonths($i)->endOfMonth();

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

        foreach ($balances as $balanceData) {
            $account->balances()->create([
                'balance_date' => $balanceData['date']->format('Y-m-d'),
                'balance' => $balanceData['balance'],
            ]);
        }
    }

    /**
     * @param  Collection<string, Category>  $categories
     * @param  array<int, array{label: Label, assignment_percentage: int}>  $labels
     */
    private function createTransactionsForAccount(Account $account, Collection $categories, array $labels): int
    {
        $transactions = $this->transactionsProvider->getTransactions();
        $count = 0;

        foreach ($transactions as $index => $transactionData) {
            $categoryName = $transactionData['category_name'];
            unset($transactionData['category_name']);

            $category = $categories->get($categoryName);

            if (! $category) {
                continue;
            }

            $encrypted = $this->encryptionService->encrypt(
                $transactionData['description'],
                $this->encryptionKey,
                "demo_tx_{$account->id}_{$index}"
            );

            $transactionData['description'] = $encrypted['encrypted'];
            $transactionData['description_iv'] = $encrypted['iv'];

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
