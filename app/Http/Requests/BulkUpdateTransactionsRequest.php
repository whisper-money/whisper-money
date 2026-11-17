<?php

namespace App\Http\Requests;

use App\Enums\CashflowBlock;
use App\Enums\CategoryType;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\LabelTransaction;
use App\Models\Transaction;
use App\Rules\AssignableLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateTransactionsRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_ids' => ['array', 'min:1'],
            'transaction_ids.*' => ['required', 'string', 'uuid'],
            'filters' => ['array'],
            'filters.date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:filters.cashflow_block'],
            'filters.date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:filters.cashflow_block'],
            'filters.cashflow_block' => ['nullable', Rule::enum(CashflowBlock::class)],
            'filters.amount_min' => ['nullable', 'numeric'],
            'filters.amount_max' => ['nullable', 'numeric'],
            'filters.category_ids' => ['nullable', 'array'],
            'filters.category_ids.*' => ['string', 'uuid', $this->userOwned('categories')],
            'filters.category_types' => ['nullable', 'array'],
            'filters.category_types.*' => [Rule::enum(CategoryType::class)],
            'filters.account_ids' => ['nullable', 'array'],
            'filters.account_ids.*' => ['string', 'uuid'],
            'filters.label_ids' => ['nullable', 'array'],
            'filters.label_ids.*' => ['string', 'uuid', $this->userOwned('labels')],
            'filters.creditor_name' => ['nullable', 'string'],
            'filters.debtor_name' => ['nullable', 'string'],
            'filters.search' => ['nullable', 'string'],
            'filters.search_text' => ['nullable', 'string'],
            'category_id' => ['nullable', $this->userOwned('categories')],
            'notes' => ['nullable', 'string'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['required', 'string', 'uuid', $this->userOwned('labels'), new AssignableLabel($this->labelsOnEverySelectedRow(...))],
        ];
    }

    /**
     * The rows the update applies to: the explicit ids when the request
     * carries them, otherwise everything matching the filters the user has on
     * screen. Always scoped to the caller.
     *
     * @return Builder<Transaction>
     */
    public function selection(): Builder
    {
        $userId = $this->user()->id;
        $query = Transaction::query()->where('user_id', $userId);
        $transactionIds = $this->input('transaction_ids');
        $filters = $this->input('filters');

        if ($transactionIds !== null) {
            return $query->whereIn('id', $transactionIds);
        }

        return $filters === null ? $query : $query->applyFilters([...$filters, 'user_id' => $userId]);
    }

    /**
     * The labels every selected row already carries. The new set replaces each
     * row's labels, so an archived label may only stay when no row would gain
     * it.
     *
     * @return list<string>
     */
    private function labelsOnEverySelectedRow(): array
    {
        $rowCount = $this->selection()->count();

        if ($rowCount === 0) {
            return [];
        }

        return LabelTransaction::query()
            ->whereIn('transaction_id', $this->selection()->select('id'))
            ->groupBy('label_id')
            ->havingRaw('count(distinct transaction_id) = ?', [$rowCount])
            ->pluck('label_id')
            ->all();
    }

    public function messages(): array
    {
        return [
            'transaction_ids.*.uuid' => 'Invalid transaction ID format.',
            'category_id.exists' => 'The selected category does not exist or does not belong to you.',
            'filters.category_ids.*.exists' => 'One or more filter categories do not exist or do not belong to you.',
            'filters.label_ids.*.exists' => 'One or more filter labels do not exist or do not belong to you.',
            'label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
        ];
    }
}
