<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Services\Transactions\TransactionSearch;
use Illuminate\Foundation\Http\FormRequest;

class SearchTransactionsRequest extends FormRequest
{
    use ChecksIdsInSpace;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = TransactionSearch::rules();

        return [
            /**
             * Free text matched against the description and the creditor and debtor names.
             *
             * @example coffee
             */
            'query' => ['sometimes', 'string', 'max:200'],
            /**
             * Only the transactions of this account.
             *
             * @format uuid
             */
            'account_id' => ['sometimes', 'string', $this->inSpace(Account::class, 'an account')],
            /**
             * Only the transactions in this category.
             *
             * @format uuid
             */
            'category_id' => ['sometimes', 'string', $this->inSpace(Category::class, 'a category')],
            'label_ids' => ['sometimes', 'array', 'max:100'],
            /**
             * Only the transactions tagged with any of these labels.
             *
             * @format uuid
             */
            'label_ids.*' => ['string', $this->inSpace(Label::class, 'a label')],
            /**
             * The earliest transaction date, inclusive.
             *
             * @format date
             *
             * @example 2026-01-01
             */
            'from' => $rules['from'],
            /**
             * The latest transaction date, inclusive.
             *
             * @format date
             *
             * @example 2026-01-31
             */
            'to' => $rules['to'],
            /**
             * The smallest signed amount, in minor units. `-5000` keeps all income and the expenses of up to 5000 minor units.
             */
            'min_amount' => ['sometimes', 'integer'],
            /**
             * The largest signed amount, in minor units. `0` keeps expenses only.
             */
            'max_amount' => ['sometimes', 'integer'],
        ];
    }
}
