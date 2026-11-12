<?php

namespace App\Services\Widgets\Gateway;

use App\Http\ApiV1\Listings;
use App\Http\Requests\Api\V1\AggregateTransactionsRequest;
use App\Http\Requests\Api\V1\AnalyticsRangeRequest;
use App\Http\Requests\Api\V1\CashflowRequest;
use App\Http\Requests\Api\V1\NetWorthRequest;
use App\Http\Requests\Api\V1\SearchTransactionsRequest;
use App\Http\Requests\Api\V1\SpendingByCategoryRequest;
use App\Http\Resources\V1\AccountResource;
use App\Http\Resources\V1\AchievementsResource;
use App\Http\Resources\V1\AutomationRuleResource;
use App\Http\Resources\V1\BudgetResource;
use App\Http\Resources\V1\CashflowResource;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\CreditCardUsageResource;
use App\Http\Resources\V1\LabelResource;
use App\Http\Resources\V1\MonthlySavingsResource;
use App\Http\Resources\V1\NetWorthResource;
use App\Http\Resources\V1\SavingsGoalResource;
use App\Http\Resources\V1\SpaceResource;
use App\Http\Resources\V1\SpendingByCategoryResource;
use App\Http\Resources\V1\SpendingByLabelResource;
use App\Http\Resources\V1\TransactionAggregateResource;
use App\Http\Resources\V1\TransactionResource;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Achievements\Progress;
use App\Services\CashflowAnalyticsService;
use App\Services\CategorySpendingService;
use App\Services\CreditCards\CreditCardStatementService;
use App\Services\LabelSpendingService;
use App\Services\NetWorthAnalyticsService;
use App\Services\SavingsGoals\CurrentMonthSavings;
use App\Services\SpaceResolver;
use App\Services\Transactions\TransactionAggregator;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `GET /api/v1` endpoints a widget may read, and the allow list of the
 * widget data gateway: an endpoint missing here does not exist for widgets.
 * Each one validates with its controller's form request and reads through the
 * same services and resources, so it answers what the API answers (a parity
 * test holds them to it). Only reads are registered: widgets never write.
 */
class EndpointRegistry
{
    /** @var array<string, Endpoint>|null */
    private ?array $endpoints = null;

    public function __construct(
        private Listings $listings,
        private SpaceResolver $spaces,
    ) {}

    /**
     * Every registered endpoint, by the name a manifest gives it (`GET /transactions`).
     *
     * @return array<string, Endpoint>
     */
    public function all(): array
    {
        if ($this->endpoints !== null) {
            return $this->endpoints;
        }

        $endpoints = [...$this->resources(), ...$this->analytics()];

        return $this->endpoints = collect($endpoints)->keyBy(fn (Endpoint $endpoint): string => $endpoint->name())->all();
    }

    public function find(string $name): ?Endpoint
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @return list<Endpoint>
     */
    private function resources(): array
    {
        return [
            $this->endpoint('/spaces', fn (Request $request, User $user): JsonResource => SpaceResource::collection($user->accessibleSpaces()), scoped: false),
            $this->list('/accounts', fn (Request $request, User $user) => $this->listings->accounts($user, $this->space($request))),
            $this->endpoint('/accounts/{account}', fn (Request $request, User $user, array $path): JsonResource => new AccountResource($this->account($request, $user, $path['account']))),
            $this->list('/accounts/{account}/balances', fn (Request $request, User $user, array $path) => $this->listings->balances($this->account($request, $user, $path['account']))),
            $this->list('/transactions', fn (SearchTransactionsRequest $request, User $user) => $this->listings->transactions($user, $this->space($request), $request->validated()), SearchTransactionsRequest::class),
            $this->endpoint('/transactions/{transaction}', fn (Request $request, User $user, array $path): JsonResource => new TransactionResource(
                $this->spaces->ownedInSpace(Transaction::query(), $user, $this->space($request))->findOrFail($path['transaction']),
            )),
            $this->list('/categories', fn (Request $request, User $user) => $this->listings->categories($user, $this->space($request))),
            $this->endpoint('/categories/{category}', fn (Request $request, User $user, array $path): JsonResource => new CategoryResource($this->inSpace($request, $user, Category::class, $path['category']))),
            $this->list('/labels', fn (Request $request, User $user) => $this->listings->labels($user, $this->space($request))),
            $this->endpoint('/labels/{label}', fn (Request $request, User $user, array $path): JsonResource => new LabelResource($this->inSpace($request, $user, Label::class, $path['label']))),
            $this->list('/automation-rules', fn (Request $request, User $user) => $this->listings->automationRules($user, $this->space($request))),
            $this->endpoint('/automation-rules/{automation_rule}', fn (Request $request, User $user, array $path): JsonResource => new AutomationRuleResource($this->inSpace($request, $user, AutomationRule::class, $path['automation_rule']))),
            $this->list('/budgets', fn (Request $request, User $user) => $this->listings->budgets($user), scoped: false),
            $this->endpoint('/budgets/{budget}', fn (Request $request, User $user, array $path): JsonResource => new BudgetResource(Budget::ownedByOrFail($user, $path['budget'])), scoped: false),
            $this->list('/savings-goals', fn (Request $request, User $user) => $this->listings->savingsGoals($user, $this->space($request))),
            $this->endpoint('/savings-goals/{savings_goal}', fn (Request $request, User $user, array $path): JsonResource => new SavingsGoalResource($this->inSpace($request, $user, SavingsGoal::class, $path['savings_goal']))),
            $this->endpoint('/achievements', fn (Request $request, User $user): JsonResource => new AchievementsResource(app(Progress::class)->for($user)), scoped: false),
        ];
    }

    /**
     * The analytics endpoints, all but `aggregate` over the user's whole account.
     *
     * @return list<Endpoint>
     */
    private function analytics(): array
    {
        return [
            $this->endpoint('/analytics/cashflow', function (CashflowRequest $request, User $user): JsonResource {
                $period = $request->period();

                return new CashflowResource(app(CashflowAnalyticsService::class)->forRange($user, $period->from, $period->to));
            }, CashflowRequest::class, scoped: false),
            $this->endpoint('/analytics/net-worth', fn (NetWorthRequest $request, User $user): JsonResource => new NetWorthResource(
                app(NetWorthAnalyticsService::class)->report($user, $request->period(), $request->daily()),
            ), NetWorthRequest::class, scoped: false),
            $this->endpoint('/analytics/spending-by-category', function (SpendingByCategoryRequest $request, User $user): JsonResource {
                $period = $request->period();

                return new SpendingByCategoryResource(
                    app(CategorySpendingService::class)->forPeriod($user->id, $period->from, $period->to, $request->parentCategoryId()),
                );
            }, SpendingByCategoryRequest::class, scoped: false),
            $this->endpoint('/analytics/spending-by-label', fn (AnalyticsRangeRequest $request, User $user): JsonResource => new SpendingByLabelResource([
                'period' => $request->period(),
                'labels' => app(LabelSpendingService::class)->comparedForPeriod($user->id, $request->period()),
            ]), AnalyticsRangeRequest::class, scoped: false),
            $this->endpoint('/analytics/credit-card-usage', fn (Request $request, User $user): JsonResource => CreditCardUsageResource::collection(app(CreditCardStatementService::class)->liveUsageFor($user)), scoped: false),
            $this->endpoint('/analytics/monthly-savings', fn (Request $request, User $user): JsonResource => new MonthlySavingsResource(app(CurrentMonthSavings::class)->forUser($user)), scoped: false),
            $this->endpoint('/analytics/aggregate', fn (AggregateTransactionsRequest $request, User $user): JsonResource => new TransactionAggregateResource(
                app(TransactionAggregator::class)->aggregate($user, $this->space($request), $request->validated()),
            ), AggregateTransactionsRequest::class),
        ];
    }

    /**
     * @param  class-string<FormRequest>|null  $request
     */
    private function endpoint(string $path, Closure $handler, ?string $request = null, bool $scoped = true): Endpoint
    {
        return new Endpoint($path, $request, $scoped, false, $handler);
    }

    /**
     * @param  class-string<FormRequest>|null  $request
     */
    private function list(string $path, Closure $handler, ?string $request = null, bool $scoped = true): Endpoint
    {
        return new Endpoint($path, $request, $scoped, true, $handler);
    }

    private function space(Request $request): Space
    {
        return $this->spaces->forApiRequest($request);
    }

    private function account(Request $request, User $user, string $id): Account
    {
        return $this->spaces->ownedInSpace(Account::query(), $user, $this->space($request))->findOrFail($id);
    }

    /**
     * @template TModel of AutomationRule|Category|Label|SavingsGoal
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    private function inSpace(Request $request, User $user, string $model, string $id): AutomationRule|Category|Label|SavingsGoal
    {
        return $this->spaces->ownedInSpaceOrFail($model, $user, $this->space($request), $id);
    }
}
