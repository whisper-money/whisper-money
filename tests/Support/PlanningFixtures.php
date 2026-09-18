<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AccountType;
use App\Enums\LabelColor;
use App\Enums\LabelSource;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;

/**
 * Seeding shared by the Planning and settings browser tests: savings goals,
 * budget archiving, labels, automation rules and account archiving.
 *
 * Every factory in play picks something at random — an account type, a
 * currency, a description, a label name — and all of it ends up on screen,
 * where these tests assert on exact strings. So each helper pins what it
 * creates instead of letting the factory choose.
 */
final class PlanningFixtures
{
    /**
     * A verified, onboarded user in USD and en-US, which is the locale the
     * currency assertions in these files are written in ("$1,000.00").
     */
    public static function user(): User
    {
        return User::factory()->onboarded()->create(['currency_code' => 'USD']);
    }

    public static function account(
        User $user,
        string $name,
        AccountType $type = AccountType::Checking,
    ): Account {
        return Account::factory()->create([
            'user_id' => $user->id,
            'bank_id' => Bank::factory()->create(['name' => $name.' Bank', 'logo' => null])->id,
            'name' => $name,
            'type' => $type,
            'currency_code' => 'USD',
        ]);
    }

    /**
     * A balance a month back, so the dashboard and the accounts page have
     * something to draw instead of an empty chart.
     */
    public static function balance(Account $account, int $amountInCents): AccountBalance
    {
        return AccountBalance::factory()->create([
            'account_id' => $account->id,
            'balance_date' => now()->subMonth()->toDateString(),
            'balance' => $amountInCents,
        ]);
    }

    /**
     * A transaction whose description is readable on screen: the factory
     * encrypts it by default, and the browser cannot decrypt it without a key.
     */
    public static function transaction(
        User $user,
        Account $account,
        string $description,
        int $amountInCents,
        ?string $categoryId = null,
    ): Transaction {
        return Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $categoryId,
            'description' => $description,
            'amount' => $amountInCents,
            'currency_code' => 'USD',
            // Relative to now so the test survives a month boundary, and far
            // enough back that it always falls inside the goal's window.
            'transaction_date' => now()->subDays(3)->toDateString(),
            'notes' => null,
            'notes_iv' => null,
        ]);
    }

    /**
     * A goal in the shape the create dialog leaves one: a hidden label of the
     * same name, which is what links transactions to it.
     *
     * The factory would name the label after its own random words even when
     * the goal's name is overridden, and that name renders as a badge on the
     * goal page.
     *
     * @return array{SavingsGoal, Label}
     */
    public static function savingsGoal(
        User $user,
        string $name,
        int $targetAmountInCents,
        ?string $targetDate = null,
    ): array {
        $label = Label::factory()->create([
            'user_id' => $user->id,
            'name' => $name,
            'color' => LabelColor::Emerald->value,
            'source' => LabelSource::SavingsGoal,
        ]);

        $goal = SavingsGoal::factory()->create([
            'user_id' => $user->id,
            'label_id' => $label->id,
            'name' => $name,
            'target_amount' => $targetAmountInCents,
            'initial_amount' => 0,
            'target_date' => $targetDate,
        ]);

        return [$goal, $label];
    }
}
