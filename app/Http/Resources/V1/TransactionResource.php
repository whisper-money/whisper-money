<?php

namespace App\Http\Resources\V1;

use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transaction: money that moved in or out of an account, synced from the
 * bank, imported from a file or recorded by hand. Amounts are in minor units.
 *
 * One part of a split carries the `split_parent_id` its siblings share; a
 * transaction still split the old way keeps its lines nested under `splits`
 * until it is converted. Fields still encrypted on
 * the device are empty, as the CSV export writes them: the server cannot read
 * them, and ciphertext is not text.
 *
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * The relations toArray() reads, for the callers to eager load.
     *
     * @var list<string>
     */
    public const array Relations = ['account:id,name,name_iv', 'category:id,name', 'labels:id,name', 'splits.category:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(self::Relations);

        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'account' => ['id' => $this->account_id, 'name' => $this->account?->name_iv === null ? $this->account?->name : ''],
            'transaction_date' => $this->transaction_date->toDateString(),
            'description' => $this->description_iv === null ? $this->description : '',
            'amount' => $this->amount,
            'currency' => $this->currency_code,
            'category' => $this->category === null ? null : ['id' => $this->category->id, 'name' => $this->category->name],
            'category_source' => $this->category_source?->value,
            'labels' => $this->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
            'source' => $this->source->value,
            'creditor_name' => $this->creditor_name,
            'debtor_name' => $this->debtor_name,
            'notes' => $this->notes_iv === null ? $this->notes : '',
            'split_parent_id' => $this->split_parent_id,
            'splits' => $this->splits
                ->map(fn (TransactionSplit $split): array => [
                    'id' => $split->id,
                    'category' => $split->category === null ? null : ['id' => $split->category->id, 'name' => $split->category->name],
                    'amount' => $split->amount,
                    'position' => $split->position,
                ])
                ->values()
                ->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
