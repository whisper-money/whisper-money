<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Http\Controllers\Api\V1\Controller;
use App\Models\Account;

/**
 * For the `/api/v1/accounts/{account}` form requests whose rules depend on
 * the account: it is looked up in the request's space, so one in another
 * space, or someone else's, is a 404 before anything is validated.
 */
trait ResolvesRouteAccount
{
    private ?Account $routeAccount = null;

    /**
     * Any member of the space may act on its accounts, so resolving the
     * account is the whole check. A request that narrows it overrides this.
     */
    public function authorize(): bool
    {
        $this->account();

        return true;
    }

    public function account(): Account
    {
        return $this->routeAccount ??= Account::query()
            ->forSpace(Controller::spaceOf($this))
            ->findOrFail($this->route('account'));
    }

    /**
     * The account, or null while the API reference is generated, which builds
     * the rules with no route to read it from.
     */
    protected function accountOrNull(): ?Account
    {
        return $this->route('account') === null ? null : $this->account();
    }
}
