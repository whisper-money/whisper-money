<?php

namespace App\Services\Transactions;

use App\Models\Transaction;

/**
 * A transaction TransactionWriter created or edited, and whether the write
 * moved its account's balance snapshots.
 */
final readonly class WrittenTransaction
{
    public function __construct(
        public Transaction $transaction,
        public bool $balanceUpdated,
    ) {}
}
