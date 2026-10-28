<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Http\ApiV1\ApiV1Exception;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SpaceResolver;

/**
 * For the `/api/v1/transactions/{transaction}` write requests: the
 * transaction is looked up in the request's space, so one in another space,
 * or someone else's, is a 404 before anything is validated. Every member of
 * the space reads its transactions, but only the one who owns a transaction
 * may change or delete it (TransactionPolicy, as in the app).
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
        $resolver = app(SpaceResolver::class);

        return $this->routeTransaction ??= $resolver->transactionOrFail($resolver->forApiRequest($this), (string) $this->route('transaction'));
    }

    protected function failedAuthorization(): never
    {
        throw new ApiV1Exception(403, 'not_transaction_owner', "Transaction {$this->transaction()->id} belongs to another member of the space, so only they can change or delete it. You can still read it.");
    }
}
