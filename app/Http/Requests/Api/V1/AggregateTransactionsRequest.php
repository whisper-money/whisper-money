<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Services\Transactions\TransactionAggregator;
use Illuminate\Foundation\Http\FormRequest;

class AggregateTransactionsRequest extends FormRequest
{
    use ChecksIdsInSpace;

    /**
     * The list parameters, which also take a comma-separated value
     * (`group_by=month,category`) besides the repeated `group_by[]=` form.
     */
    private const LIST_FIELDS = ['group_by', 'metrics', 'types', 'category_ids', 'label_ids', 'account_ids'];

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = TransactionAggregator::rules();

        return [
            'group_by' => $rules['group_by'],
            /**
             * Up to two dimensions to group by, at most one of them a period (`day`, `week`, `month` or `year`). A week is named by its Monday. Without it, the transactions are added up per currency only. Also takes a comma-separated list: `group_by=month,category`.
             */
            'group_by.*' => $rules['group_by.*'],
            'metrics' => $rules['metrics'],
            /**
             * What to work out over the amounts of each bucket. Defaults to `sum` and `count`.
             */
            'metrics.*' => $rules['metrics.*'],
            /**
             * The earliest transaction date, inclusive.
             *
             * @format date
             *
             * @example 2026-10-01
             */
            'from' => $rules['from'],
            /**
             * The latest transaction date, inclusive. Not before `from`.
             *
             * @format date
             *
             * @example 2026-10-31
             */
            'to' => $rules['to'],
            'category_ids' => $rules['category_ids'],
            /**
             * Only the transactions in any of these categories.
             *
             * @format uuid
             */
            'category_ids.*' => [...$rules['category_ids.*'], $this->inSpace(Category::class, 'a category')],
            /**
             * Whether `category_ids` also matches their subcategories. Defaults to `true`.
             *
             * @example true
             */
            'include_subcategories' => $rules['include_subcategories'],
            'label_ids' => $rules['label_ids'],
            /**
             * Only the transactions tagged with any of these labels. Grouped by `label`, only these labels are listed.
             *
             * @format uuid
             */
            'label_ids.*' => [...$rules['label_ids.*'], $this->inSpace(Label::class, 'a label')],
            'account_ids' => $rules['account_ids'],
            /**
             * Only the transactions of any of these accounts.
             *
             * @format uuid
             */
            'account_ids.*' => [...$rules['account_ids.*'], $this->inSpace(Account::class, 'an account')],
            'types' => $rules['types'],
            /**
             * Only the transactions of these types: `income`, `expense`, `transfer`, `savings` or `investment`. A transaction's type is its category's, and an uncategorized one is `expense` when it took money out and `income` otherwise.
             *
             * @example ["expense"]
             */
            'types.*' => $rules['types.*'],
            /**
             * Free text matched against the description and the creditor and debtor names.
             *
             * @example coffee
             */
            'query' => $rules['query'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $lists = [];

        foreach (self::LIST_FIELDS as $field) {
            if (is_string($value = $this->input($field))) {
                $lists[$field] = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== ''));
            }
        }

        $includeSubcategories = $this->input('include_subcategories');

        if (is_string($includeSubcategories) && in_array(strtolower($includeSubcategories), ['true', 'false'], true)) {
            $lists['include_subcategories'] = strtolower($includeSubcategories) === 'true';
        }

        $this->merge($lists);
    }
}
