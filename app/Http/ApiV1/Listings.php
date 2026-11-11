<?php

namespace App\Http\ApiV1;

use App\Http\Resources\V1\AccountBalanceResource;
use App\Http\Resources\V1\AccountResource;
use App\Http\Resources\V1\AutomationRuleResource;
use App\Http\Resources\V1\BudgetResource;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\LabelResource;
use App\Http\Resources\V1\SavingsGoalResource;
use App\Http\Resources\V1\TransactionResource;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SpaceResolver;
use App\Services\Transactions\TransactionSearch;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The lists the `/api/v1` list endpoints answer, shared by their controllers
 * and the widget data gateway so both read exactly the same rows: as in the
 * app, only the caller's own ones in the space.
 */
class Listings
{
    public function __construct(
        private SpaceResolver $spaces,
        private TransactionSearch $transactionSearch,
    ) {}

    /**
     * @return Listing<Account>
     */
    public function accounts(User $user, Space $space): Listing
    {
        return new Listing(
            $this->spaces->ownedInSpace(Account::query(), $user, $space)->with(['bank:id,name', 'creditCardDetail']),
            Listing::NewestFirst,
            AccountResource::class,
        );
    }

    /**
     * @return Listing<AccountBalance>
     */
    public function balances(Account $account): Listing
    {
        return new Listing(
            $account->balances()->getQuery(),
            [['balance_date', 'desc'], ['id', 'desc']],
            AccountBalanceResource::class,
        );
    }

    /**
     * @param  array<string, mixed>  $filters  validated by SearchTransactionsRequest
     * @return Listing<Transaction>
     */
    public function transactions(User $user, Space $space, array $filters): Listing
    {
        return new Listing(
            $this->transactionSearch->query($user, $space, $filters)->with(TransactionResource::Relations),
            [['transaction_date', 'desc'], ['id', 'desc']],
            TransactionResource::class,
        );
    }

    /**
     * @return Listing<Category>
     */
    public function categories(User $user, Space $space): Listing
    {
        return new Listing($this->spaces->ownedInSpace(Category::query(), $user, $space), Listing::NewestFirst, CategoryResource::class);
    }

    /**
     * @return Listing<Label>
     */
    public function labels(User $user, Space $space): Listing
    {
        return new Listing($this->spaces->ownedInSpace(Label::query(), $user, $space), Listing::NewestFirst, LabelResource::class);
    }

    /**
     * @return Listing<AutomationRule>
     */
    public function automationRules(User $user, Space $space): Listing
    {
        return new Listing(
            $this->spaces->ownedInSpace(AutomationRule::query(), $user, $space)->with('labels:id'),
            [['priority', 'asc'], ['title', 'asc'], ['id', 'asc']],
            AutomationRuleResource::class,
        );
    }

    /**
     * Budgets cover the user's whole account rather than a space.
     *
     * @return Listing<Budget>
     */
    public function budgets(User $user): Listing
    {
        return new Listing(
            Budget::query()->whereBelongsTo($user)->notArchived()->with([
                'categories:id,name',
                'labels:id,name',
                'currentPeriods' => fn (Relation $periods) => $periods->withSum('budgetTransactions', 'amount'),
            ]),
            Listing::NewestFirst,
            BudgetResource::class,
        );
    }

    /**
     * @return Listing<SavingsGoal>
     */
    public function savingsGoals(User $user, Space $space): Listing
    {
        return new Listing(
            $this->spaces->ownedInSpace(SavingsGoal::query(), $user, $space)->with(['user:id,currency_code', 'label' => fn ($query) => $query->withTrashed()]),
            Listing::NewestFirst,
            SavingsGoalResource::class,
        );
    }
}
