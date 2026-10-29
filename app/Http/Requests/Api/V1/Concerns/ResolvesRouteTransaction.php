<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Transaction;
use App\Models\User;
use App\Services\SpaceResolver;

/**
 * For the `/api/v1/transactions/{transaction}` write requests: the
 * transaction is looked up among the caller's own in the request's space, so
 * one in another space, or another member's, is a 404 before anything is
 * validated. TransactionPolicy, as in the app, is checked again on it.
 */
trait ResolvesRouteTransaction
{
    private ?Transaction $routeTransaction = null;

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can($this->isMethod('DELETE') ? 'delete' : 'update', $this->transaction());
    }

    public function transaction(): Transaction
    {
        return $this->routeTransaction ??= app(SpaceResolver::class)->ownedForApiRequest($this, Transaction::query())->findOrFail((string) $this->route('transaction'));
    }
}
