<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SpaceResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Base for the public `/api/v1` controllers. The authenticated user is always
 * a User here: AuthenticateApiRequest runs on the whole group.
 */
abstract class Controller extends BaseController
{
    protected function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    /**
     * The space the request operates on: the optional `space` query or body
     * parameter, or the user's personal space. An inaccessible id is a 422.
     */
    protected function space(Request $request): Space
    {
        return app(SpaceResolver::class)->forApiRequest($request);
    }

    /**
     * Narrows $query to the caller's own rows in the request's space: as in the app,
     * another member's rows are never listed, read or changed.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function owned(Request $request, Builder $query): Builder
    {
        return app(SpaceResolver::class)->ownedForApiRequest($request, $query);
    }

    /**
     * The route's account, looked up among the caller's own in the request's
     * space: one in another space, or another member's, is a 404 like one
     * that does not exist.
     */
    protected function account(Request $request, string $id): Account
    {
        return $this->owned($request, Account::query())->findOrFail($id);
    }

    /**
     * The route's transaction, looked up in the request's space like account().
     */
    protected function transaction(Request $request, string $id): Transaction
    {
        return $this->owned($request, Transaction::query())->findOrFail($id);
    }

    /**
     * The route's category, label, automation rule or savings goal, looked up
     * among the caller's own in the request's space like account().
     *
     * @template TModel of AutomationRule|Category|Label|SavingsGoal
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    protected function inSpace(Request $request, string $model, string $id): AutomationRule|Category|Label|SavingsGoal
    {
        return app(SpaceResolver::class)->ownedInSpaceOrFail($model, $this->user($request), $this->space($request), $id);
    }

    /**
     * The route's budget. Budgets cover the user's whole account rather than a
     * space, so one of someone else's is a 404 like one that does not exist.
     */
    protected function budget(Request $request, string $id): Budget
    {
        return Budget::ownedByOrFail($this->user($request), $id);
    }
}
