<?php

namespace App\Console\Commands;

use App\Actions\CreateDefaultCategories;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Label;
use App\Models\User;
use App\Services\Demo\DemoAutomationRulesProvider;
use App\Services\Demo\DemoLabelsProvider;
use App\Services\Demo\DemoTransactionsProvider;
use Illuminate\Console\Command;

class ResetDemoAccountCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Reset the demo account with fresh data';

    public function __construct(
        private DemoTransactionsProvider $transactionsProvider,
        private DemoLabelsProvider $labelsProvider,
        private DemoAutomationRulesProvider $rulesProvider,
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

        $user = $this->findOrCreateDemoUser($demoEmail, $demoPassword, $demoEncryptionKey);

        $this->deleteExistingData($user);

        $this->createCategories($user);

        $labels = $this->createLabels($user);

        $this->createAccountsWithTransactions($user, $labels);

        $this->createAutomationRules($user, $labels);

        $this->info('✓ Demo account reset successfully!');

        return self::SUCCESS;
    }

    private function findOrCreateDemoUser(string $email, string $password, ?string $encryptionKey): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Demo User',
                'password' => $password,
                'email_verified_at' => now(),
                'onboarded_at' => now(),
                'encryption_salt' => $encryptionKey,
                'currency_code' => 'USD',
            ]
        );
    }

    private function deleteExistingData(User $user): void
    {
        $user->transactions()->forceDelete();
        $user->accounts()->forceDelete();
        $user->labels()->forceDelete();
        $user->automationRules()->forceDelete();
        $user->categories()->forceDelete();

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
        $bank = Bank::whereNull('user_id')->first() ?? Bank::factory()->create(['user_id' => null]);

        $accounts = [
            ['name' => 'Primary Checking', 'type' => AccountType::Checking],
            ['name' => 'Secondary Checking', 'type' => AccountType::Checking],
            ['name' => 'Emergency Savings', 'type' => AccountType::Savings],
            ['name' => '401k Retirement', 'type' => AccountType::Retirement],
            ['name' => 'Investment Portfolio', 'type' => AccountType::Investment],
        ];

        foreach ($accounts as $accountData) {
            $account = $user->accounts()->create([
                'name' => $accountData['name'],
                'name_iv' => 'demo_iv_'.uniqid(),
                'bank_id' => $bank->id,
                'currency_code' => 'USD',
                'type' => $accountData['type'],
            ]);

            $this->createTransactionsForAccount($user, $account, $labels);
        }

        $this->info('  Created 5 accounts with transactions');
    }

    /**
     * @param  array<int, array{label: Label, assignment_percentage: int}>  $labels
     */
    private function createTransactionsForAccount(User $user, Account $account, array $labels): void
    {
        $transactions = $this->transactionsProvider->getTransactions();
        $category = $user->categories()->first();

        foreach ($transactions as $transactionData) {
            $transaction = $user->transactions()->create([
                'account_id' => $account->id,
                'category_id' => $category?->id,
                ...$transactionData,
            ]);

            foreach ($labels as $labelConfig) {
                if (rand(1, 100) <= $labelConfig['assignment_percentage']) {
                    $transaction->labels()->attach($labelConfig['label']->id);
                }
            }
        }
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
}
