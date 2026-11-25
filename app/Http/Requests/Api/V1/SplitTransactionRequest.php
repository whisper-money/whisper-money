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
     * The shape of the parts, from {@see TransactionSplitter::partRules()},
     * with every category and label checked as one of the caller's own in the
     * space. What keeps a split honest (the parts add up and move money the
     * same way) is the splitter's, checked in after(). An oversized list gets
     * no rules for its parts: see idListInSpace().
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = TransactionSplitter::partRules();
        $parts = $this->input('splits');

        return [
            'splits' => [...$rules['splits'], 'list'],
            ...is_array($parts) && count($parts) > TransactionSplitter::MAX_PARTS ? [] : [
                'splits.*' => $rules['splits.*'],
                'splits.*.amount' => $rules['splits.*.amount'],
                'splits.*.category_id' => [...$rules['splits.*.category_id'], $this->inSpace(Category::class, 'a category')],
                'splits.*.label_ids' => [...$rules['splits.*.label_ids'], 'list'],
                'splits.*.label_ids.*' => [...$rules['splits.*.label_ids.*'], $this->inSpace(Label::class, 'a label')],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TransactionSplitter::partMessages();
    }

    /**
     * Refuse unknown fields, a transaction that is already one part of a
     * split or is still split into the old lines, and parts that do not add
     * up to it. The parts are only checked once their shape is valid.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        $transaction = $this->transaction();

        return [
            ...$this->refusingUnknownFieldsAnd(fn (): array => [
                'transaction' => TransactionSplitter::splitPartError($transaction) ?? TransactionSplitter::legacyLinesError($transaction),
            ]),
            function (Validator $validator) use ($transaction): void {
                if ($validator->errors()->isEmpty()) {
                    $this->addRefusals($validator, array_filter([
                        'splits' => TransactionSplitter::partsError($transaction, $this->splits()),
                    ]));
                }
            },
        ];
    }

    /**
     * The parts as TransactionSplitter takes them, their ids as stored: the
     * lookup ignores case. Only read once they passed the rules.
     *
     * @return list<array{amount: int, category_id: ?string, label_ids: list<string>}>
     */
    public function splits(): array
    {
        return array_map(fn (array $part): array => [
            'amount' => (int) $part['amount'],
            'category_id' => isset($part['category_id'])
                ? $this->idInSpace(Category::class, 'splits.*.category_id', (string) $part['category_id'])
                : null,
            'label_ids' => array_map(
                fn (string $id): string => $this->idInSpace(Label::class, 'splits.*.label_ids.*', $id),
                array_values($part['label_ids'] ?? []),
            ),
        ], array_values((array) $this->input('splits')));
    }
}
