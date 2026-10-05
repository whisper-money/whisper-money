<?php

namespace App\Http\Requests\Api;

use App\Enums\CategoryColor;
use App\Enums\CategoryType;
use App\Enums\ImportAccountAction;
use App\Enums\ImportCategoryAction;
use App\Enums\ImportMode;
use App\Enums\ImportSource;
use App\Models\Bank;
use App\Services\CurrencyOptions;
use App\Services\Imports\ImportPlanValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The plan of a full import: what to do with each account and category found
 * in the file, and how many rows the browser is about to upload for it.
 */
class StoreFullImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canUseFullImport();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::enum(ImportSource::class)],
            'file_name' => ['nullable', 'string', 'max:255'],
            'mode' => ['required', Rule::enum(ImportMode::class)],
            'confirm_wipe' => [Rule::requiredIf($this->input('mode') === ImportMode::Wipe->value), 'accepted_if:mode,wipe'],
            ...$this->profileRules(),
            ...$this->accountRules(),
            ...$this->categoryRules(),
            'expected_transactions' => ['required', 'integer', 'min:1', 'max:100000'],
            'expected_balances' => ['required', 'integer', 'min:0', 'max:200000'],
        ];
    }

    /**
     * The column layout the wizard remembers for the next file of the same
     * source. Opaque to the server beyond its shape.
     *
     * @return array<string, array<mixed>>
     */
    private function profileRules(): array
    {
        return [
            'profile' => ['nullable', 'array'],
            'profile.columns' => ['nullable', 'array', 'max:20'],
            'profile.columns.*' => ['nullable', 'string', 'max:255'],
            'profile.date_format' => ['nullable', 'string', 'max:20'],
            'profile.category_separator' => ['nullable', 'string', 'max:5'],
            'profile.split_accounts' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function accountRules(): array
    {
        return [
            'accounts' => ['required', 'array', 'min:1', 'max:200'],
            'accounts.*.key' => ['required', 'string', 'max:255', 'distinct'],
            'accounts.*.action' => ['required', Rule::enum(ImportAccountAction::class)],
            'accounts.*.name' => ['required_if:accounts.*.action,create', 'nullable', 'string', 'max:255'],
            'accounts.*.type' => ['required_if:accounts.*.action,create', 'nullable', Rule::in(ImportPlanValidator::transactionalTypeValues())],
            'accounts.*.currency_code' => ['required_if:accounts.*.action,create', 'nullable', Rule::in(app(CurrencyOptions::class)->accountCodes())],
            'accounts.*.bank_id' => ['nullable', 'uuid', Rule::exists(Bank::class, 'id')->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $this->user()->id))],
            'accounts.*.iban' => ['nullable', 'string', 'max:64'],
            'accounts.*.target_account_id' => ['required_if:accounts.*.action,map', 'nullable', 'uuid'],
            'accounts.*.merge_into_key' => ['required_if:accounts.*.action,merge', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function categoryRules(): array
    {
        return [
            'categories' => ['present', 'array', 'max:2000'],
            'categories.*.key' => ['required', 'string', 'max:800', 'distinct'],
            'categories.*.action' => ['required', Rule::enum(ImportCategoryAction::class)],
            'categories.*.category_id' => ['required_if:categories.*.action,match', 'nullable', 'uuid'],
            'categories.*.name' => ['required_if:categories.*.action,create', 'nullable', 'string', 'max:255'],
            'categories.*.parent_key' => ['nullable', 'string', 'max:800'],
            'categories.*.type' => ['nullable', Rule::enum(CategoryType::class)],
            'categories.*.icon' => ['required_if:categories.*.action,create', 'nullable', 'string', 'max:64'],
            'categories.*.color' => ['required_if:categories.*.action,create', 'nullable', Rule::enum(CategoryColor::class)],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                app(ImportPlanValidator::class)->validate(
                    $validator,
                    $this->user(),
                    $this->user()->activeSpace()->id,
                    $this->all(),
                );
            },
        ];
    }
}
