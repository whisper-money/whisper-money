<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Account;
use App\Models\User;
use App\Services\SpaceResolver;

/**
 * For the `/api/v1/accounts/{account}` form requests whose rules depend on
 * the account: it is looked up among the caller's own in the request's
 * space, so one in another space, or another member's, is a 404 before
 * anything is validated.
 */
trait ResolvesRouteAccount
{
    private ?Account $routeAccount = null;

    /**
     * Only the caller's own accounts resolve, so this is the AccountPolicy
     * check the app runs, made again on the resolved account.
     */
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can('update', $this->account());
    }

    public function account(): Account
    {
        return $this->routeAccount ??= app(SpaceResolver::class)->ownedForApiRequest($this, Account::query())->findOrFail((string) $this->route('account'));
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
