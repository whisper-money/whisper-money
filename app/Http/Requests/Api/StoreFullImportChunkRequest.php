<?php

namespace App\Http\Requests\Api;

use App\Enums\ImportChunkKind;
use App\Models\Import;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One slice of a full import's rows: movements or daily balances, already
 * normalised by the browser into amounts in minor units and ISO dates.
 */
class StoreFullImportChunkRequest extends FormRequest
{
    /** Small enough to validate quickly, large enough to keep the request count down. */
    private const MAX_ROWS = 500;

    /**
     * Ownership is checked by the controller through the ImportPolicy.
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
        $isTransactions = $this->input('kind') === ImportChunkKind::Transactions->value;

        return [
            'kind' => ['required', Rule::enum(ImportChunkKind::class)],
            'position' => ['required', 'integer', 'min:0', 'max:100000'],
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.account_key' => ['required', 'string', Rule::in($this->planKeys('accounts'))],
            'rows.*.date' => ['required', 'date_format:Y-m-d'],
            ...$isTransactions ? $this->transactionRules() : $this->balanceRules(),
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function transactionRules(): array
    {
        return [
            'rows.*.amount' => ['required', 'integer'],
            'rows.*.description' => ['required', 'string', 'max:2000'],
            'rows.*.notes' => ['nullable', 'string', 'max:5000'],
            'rows.*.category_key' => ['nullable', 'string', Rule::in($this->planKeys('categories'))],
            'rows.*.external_id' => ['nullable', 'string', 'max:255'],
            'rows.*.currency_code' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function balanceRules(): array
    {
        return [
            'rows.*.balance' => ['required', 'integer'],
        ];
    }

    /**
     * The keys the import's plan declared, which every row has to use.
     *
     * @return list<string>
     */
    private function planKeys(string $section): array
    {
        $import = $this->route('import');

        if (! $import instanceof Import) {
            return [];
        }

        return array_values(array_map(
            fn (array $entry): string => (string) $entry['key'],
            $import->plan[$section] ?? [],
        ));
    }
}
