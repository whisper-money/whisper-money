<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionUpdated
{
    use Dispatchable, SerializesModels;

    /**
     * @var list<string>
     */
    public array $changedAttributes;

    public bool $labelsChanged;

    public function __construct(public Transaction $transaction)
    {
        $this->changedAttributes = array_keys($transaction->getChanges());
        $this->labelsChanged = $transaction->relationLoaded('budgetRelevantLabelsChanged')
            && (bool) $transaction->getRelation('budgetRelevantLabelsChanged');
    }

    /**
     * @param  list<string>  $attributes
     */
    public function changedAny(array $attributes): bool
    {
        return $this->labelsChanged || array_intersect($this->changedAttributes, $attributes) !== [];
    }
}
