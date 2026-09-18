<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The seed behind the /transactions browser tests: one onboarded user with two
 * accounts, four categories, two labels and eight movements whose description,
 * amount, account, category and date are all pinned, so every filter the page
 * offers narrows the list down to a known set of rows.
 *
 * Dates are absolute rather than relative to now. Nothing the page renders
 * reads the clock, and a fixed spring 2026 keeps a run on the last day of a
 * month from pushing a movement into a different one.
 */
final class TransactionsPageFixture
{
    /** The page size TransactionController@index paginates with. */
    public const int PAGE_SIZE = 50;

    private function __construct(
        public User $user,
        public Account $checking,
        public Account $savings,
        public Category $groceries,
        public Category $transport,
        public Category $utilities,
        public Category $salary,
        public Label $holiday,
        public Label $commute,
    ) {}

    public static function seed(): self
    {
        $user = User::factory()->onboarded()->create();
        $bank = Bank::factory()->create(['name' => 'Fixture Bank']);

        $fixture = new self(
            user: $user,
            checking: self::account($user, $bank, 'Everyday Checking', AccountType::Checking),
            savings: self::account($user, $bank, 'Rainy Day Savings', AccountType::Savings),
            groceries: self::category($user, 'Groceries', CategoryType::Expense),
            transport: self::category($user, 'Transport', CategoryType::Expense),
            utilities: self::category($user, 'Utilities', CategoryType::Expense),
            salary: self::category($user, 'Salary', CategoryType::Income),
            holiday: Label::factory()->create(['user_id' => $user->id, 'name' => 'Holiday']),
            commute: Label::factory()->create(['user_id' => $user->id, 'name' => 'Commute']),
        );

        $fixture->seedNamedMovements();

        return $fixture;
    }

    /**
     * A plaintext movement of this fixture's user. Defaults to the checking
     * account, which is the one most rows belong to.
     */
    public function movement(
        string $description,
        int $amount,
        string $date,
        ?Category $category = null,
        ?Account $account = null,
    ): Transaction {
        return Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => ($account ?? $this->checking)->id,
            'category_id' => $category?->id,
            'description' => $description,
            'notes' => null,
            'amount' => $amount,
            'currency_code' => 'USD',
            'transaction_date' => $date,
        ]);
    }

    public function movementNamed(string $description): Transaction
    {
        return Transaction::query()
            ->where('user_id', $this->user->id)
            ->where('description', $description)
            ->sole();
    }

    /**
     * The eight rows the filter tests assert on. Between them they cover both
     * accounts, three expense categories, one income category and both labels,
     * so no filter leaves the list either empty or untouched.
     */
    private function seedNamedMovements(): void
    {
        $this->movement('Weekly groceries market', -4210, '2026-05-18', $this->groceries);
        $this->movement('Monthly train pass', -6500, '2026-05-12', $this->transport)
            ->labels()->attach($this->commute);
        $this->movement('Electricity bill May', -8300, '2026-05-06', $this->utilities, $this->savings);
        $this->movement('Salary payment May', 250000, '2026-05-01', $this->salary);
        $this->movement('Hotel in Lisbon', -12000, '2026-04-22', $this->transport, $this->savings)
            ->labels()->attach($this->holiday);
        $this->movement('Taxi to the airport', -3400, '2026-04-18', $this->transport, $this->savings)
            ->labels()->attach($this->holiday);
        $this->movement('Coffee subscription', -1500, '2026-04-09', $this->groceries);
        $this->movement('Gym membership fee', -2900, '2026-03-27', $this->utilities, $this->savings);
    }

    /**
     * One movement per day going back from March, for the tests that need more
     * than a single page. Each gets its own date so the order the list loads
     * them in is decided by the data rather than by the id tiebreaker, which is
     * a random UUID.
     */
    public function addOlderMovements(int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            $this->movement(
                sprintf('Older movement %02d', $index),
                -1000,
                Carbon::parse('2026-03-20')->subDays($index - 1)->toDateString(),
                $this->groceries,
            );
        }
    }

    private static function account(User $user, Bank $bank, string $name, AccountType $type): Account
    {
        return Account::factory()->create([
            'user_id' => $user->id,
            'bank_id' => $bank->id,
            'name' => $name,
            'currency_code' => 'USD',
            'type' => $type,
        ]);
    }

    private static function category(User $user, string $name, CategoryType $type): Category
    {
        return Category::factory()->create([
            'user_id' => $user->id,
            'name' => $name,
            'type' => $type,
        ]);
    }
}
