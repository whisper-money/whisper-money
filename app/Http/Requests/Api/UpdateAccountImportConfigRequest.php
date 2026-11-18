<?php

namespace App\Http\Requests\Api;

use App\Enums\ImportConfigType;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountImportConfigRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via the AccountPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ImportConfigType::class)],
            'config' => ['required', 'array'],
            ...($this->input('type') === ImportConfigType::Broker->value
                ? $this->brokerRules()
                : $this->columnMappingRules()),
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function columnMappingRules(): array
    {
        return [
            'config.columnMapping' => ['required', 'array'],
            'config.dateFormat' => ['required', 'string', 'max:20'],
            // True while the saved mapping is the AI's suggestion, unchanged.
            'config.aiSuggested' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * What a broker import remembers on the account its file went to, so
     * the next import of the same broker picks the same accounts.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    private function brokerRules(): array
    {
        return [
            'config.broker' => ['required', 'string', Rule::in(['trade_republic', 'myinvestor_cash', 'myinvestor_securities'])],
            'config.portfolioAccountId' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists(Account::class, 'id')->where('user_id', $this->user()?->id),
            ],
            'config.lastImportedAt' => ['required', 'date'],
            'config.lastValueMinor' => ['sometimes', 'nullable', 'integer'],
            'config.lastValueDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'config.lastInvestedMinor' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
