<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Transaction;
use App\Services\SpaceResolver;

/**
 * For the `/api/v1/transactions/{transaction}` form requests: the transaction
 * is looked up in the request's space, so one in another space, or someone
 * else's, is a 404 before anything is validated. Any member of the space may
 * act on its transactions, so resolving it is the whole check.
 */
trait ResolvesRouteTransaction
{
    private ?Transaction $routeTransaction = null;

    public function authorize(): bool
    {
        $this->transaction();

        return true;
    }

    public function transaction(): Transaction
    {
        $resolver = app(SpaceResolver::class);

        return $this->routeTransaction ??= $resolver->transactionOrFail($resolver->forApiRequest($this), (string) $this->route('transaction'));
    }
}
