<?php

namespace App\Http\Resources\V1;

use App\Models\Label;
use App\Models\Transaction;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A transaction: money that moved in or out of an account, synced from the
 * bank, imported from a file or recorded by hand.
 *
 * @mixin Transaction
 */
#[SchemaName('Transaction')]
class TransactionResource extends JsonResource
{
    /**
     * The relations toArray() reads, for the callers to eager load.
     *
     * @var list<string>
     */
    public const array Relations = ['account:id,name', 'category:id,name', 'labels:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(self::Relations);

        return [
            /**
             * The transaction's id.
             *
             * @format uuid
             *
             * @example 0199a3f8-4c5d-7e6f-8a9b-0c1d2e3f4a5b
             */
            'id' => $this->id,
            /**
             * The id of the space the transaction is in, its account's.
             *
             * @format uuid
             *
             * @example 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
             */
            'space_id' => $this->space_id,
            /**
             * The account the transaction is on: its id and name.
             *
             * @example {"id": "0199a3f4-1d2e-7c3b-8a4f-5e6d7c8b9a01", "name": "Main checking"}
             */
            'account' => ['id' => $this->account_id, 'name' => $this->account?->name],
            /**
             * The day the transaction happened.
             *
             * @format date
             *
             * @example 2026-01-31
             */
            'transaction_date' => $this->transaction_date->toDateString(),
            /**
             * What the transaction was for.
             *
             * @example Groceries at the market
             */
            'description' => $this->description,
            /**
             * The signed amount, in the minor units of `currency`: negative for an expense, positive for income.
             *
             * @example -4250
             */
            'amount' => $this->amount,
            /**
             * The ISO 4217 currency the amount is in.
             *
             * @example EUR
             */
            'currency' => $this->currency_code,
            /**
             * The category, if one is set: its id and name.
             *
             * @example {"id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f", "name": "Groceries"}
             */
            'category' => $this->category === null ? null : ['id' => $this->category->id, 'name' => $this->category->name],
            /**
             * Who set the category: `manual` (you), `rule` (an automation rule), `ai` (the automatic categorization) or `bank`. `null` while there is none.
             *
             * @var 'manual'|'rule'|'ai'|'bank'|null
             *
             * @example manual
             */
            'category_source' => $this->category_source?->value,
            /**
             * The labels the transaction is tagged with: their ids and names.
             *
             * @var list<array{id: string, name: string}>
             *
             * @example [{"id": "0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a", "name": "Holidays"}]
             */
            'labels' => $this->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
            /**
             * Where the transaction came from: `manually_created` (recorded by hand), `imported` (from a file), `enablebanking` or `wise` (a bank sync). Only a manually-created one can change its description, amount, date, currency, account or counterparty names, and only a manually-created one can be deleted; notes and the category can be changed on any.
             *
             * @var 'manually_created'|'imported'|'enablebanking'|'wise'
             *
             * @example manually_created
             */
            'source' => $this->source->value,
            /**
             * Who was paid, or `null`.
             *
             * @example Fresh Market
             */
            'creditor_name' => $this->creditor_name,
            /**
             * Who paid, or `null`.
             *
             * @example null
             */
            'debtor_name' => $this->debtor_name,
            /**
             * Free-text notes, or `null`.
             *
             * @example null
             */
            'notes' => $this->notes,
            /**
             * Set when this transaction is one part of a split, otherwise `null`. Every part of the same split carries the same value. The split original is out of every list, and a part cannot be deleted or have its core fields changed on its own.
             *
             * @format uuid
             *
             * @example null
             */
            'split_parent_id' => $this->split_parent_id,
            /**
             * When the transaction was recorded in Whisper Money.
             *
             * @format date-time
             *
             * @example 2026-01-31T18:12:00+00:00
             */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
