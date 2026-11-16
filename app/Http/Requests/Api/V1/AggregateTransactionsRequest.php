<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Http\Requests\Api\V1\Concerns\RefusesUnknownParameters;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Services\Transactions\TransactionAggregator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `group_by`: up to two dimensions, at most one of them a period (`day`,
 * `week`, `month` or `year`); a week is named by its Monday. `metrics`:
 * `sum`, `count`, `avg`, `min`, `max` (default `sum` and `count`). `from` and
 * `to`: the inclusive range, `Y-m-d`. `category_ids` (with their
 * subcategories unless `include_subcategories` is false), `label_ids`,
 * `account_ids`: the caller's own in the space. `types`: the category types
 * to keep, an uncategorized row being `expense` when money went out and
 * `income` otherwise. `query`: free text over the readable description,
 * notes and counterparty names. Lists also take a comma-separated string.
 */
class AggregateTransactionsRequest extends FormRequest
{
    use ChecksIdsInSpace;
    use RefusesUnknownParameters;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $max = TransactionAggregator::MAX_IDS;

        return [
            ...TransactionAggregator::rules(),
            ...$this->idListInSpace('category_ids', 'sometimes', $max, Category::class, 'a category'),
            ...$this->idListInSpace('label_ids', 'sometimes', $max, Label::class, 'a label'),
            ...$this->idListInSpace('account_ids', 'sometimes', $max, Account::class, 'an account'),
        ];
    }

    /**
     * Refuse a key the endpoint does not take, `space` aside, as the lists do,
     * so a misspelt filter is never read as no filter.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->refuseUnknownParameters($validator, [...array_keys($this->rules()), 'space'])];
    }

    /**
     * The validated parameters, with every id as it is stored.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            ...$this->validated(),
            'category_ids' => $this->idListAsStored(Category::class, 'category_ids'),
            'label_ids' => $this->idListAsStored(Label::class, 'label_ids'),
            'account_ids' => $this->idListAsStored(Account::class, 'account_ids'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(TransactionAggregator::normalize($this->all()));
    }
}
