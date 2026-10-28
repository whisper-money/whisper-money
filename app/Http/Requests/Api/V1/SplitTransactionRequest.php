<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Services\TransactionSplitter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SplitTransactionRequest extends FormRequest
{
    use AddsRefusals, ChecksIdsInSpace, ResolvesRouteTransaction;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = TransactionSplitter::partRules();

        return [
            /**
             * The parts to replace the transaction with, 2 to 20 of them. Their amounts must add up to the transaction's amount exactly.
             *
             * @example [{"amount": -3000, "category_id": "0199a3f5-7a8b-7c9d-8e0f-1a2b3c4d5e6f", "label_ids": ["0199a3f7-3b4c-7d5e-8f6a-7b8c9d0e1f2a"]}, {"amount": -1250}]
             */
            'splits' => $rules['splits'],
            /**
             * The part's signed amount, in the minor units of the transaction's currency. It has the sign of the original: the parts of an expense are all negative.
             *
             * @example -3000
             */
            'splits.*.amount' => $rules['splits.*.amount'],
            /**
             * The id of the part's category, a category of the space. Left out or `null`, the part is uncategorized.
             *
             * @format uuid
             */
            'splits.*.category_id' => [...$rules['splits.*.category_id'], $this->inSpace(Category::class, 'a category')],
            /**
             * The ids of the part's labels, each a label of the space. Defaults to none: the original's labels are not copied.
             */
            'splits.*.label_ids' => $rules['splits.*.label_ids'],
            /**
             * @format uuid
             */
            'splits.*.label_ids.*' => [...$rules['splits.*.label_ids.*'], $this->inSpace(Label::class, 'a label')],
        ];
    }

    /**
     * Refuse unknown fields, a transaction that is already one part of a
     * split, and parts that do not add up to it. The parts are only checked
     * once their shape is valid.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->addRefusals($validator, [
                ...$this->unknownFieldRefusals(),
                ...array_filter(['transaction' => TransactionSplitter::splitPartError($this->transaction())]),
            ]);

            if ($validator->errors()->isEmpty()) {
                $this->addRefusals($validator, array_filter([
                    'splits' => TransactionSplitter::partsError($this->transaction(), $this->splits()),
                ]));
            }
        }];
    }

    /**
     * The validated parts, in the shape TransactionSplitter takes.
     *
     * @return list<array{amount: int, category_id: ?string, label_ids: list<string>}>
     */
    public function splits(): array
    {
        return collect((array) $this->input('splits'))
            ->map(fn (array $part): array => [
                'amount' => (int) $part['amount'],
                'category_id' => $part['category_id'] ?? null,
                'label_ids' => array_values($part['label_ids'] ?? []),
            ])
            ->values()
            ->all();
    }
}
