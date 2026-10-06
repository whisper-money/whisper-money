<?php

namespace App\Http\Requests\Api;

use App\Enums\ImportChunkKind;
use App\Models\Import;
use App\Services\CurrencyOptions;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One slice of a full import's rows: movements or daily balances, already
 * normalised by the browser into amounts in minor units and ISO dates.
 */
class StoreFullImportChunkRequest extends FormRequest
{
    /** Small enough to validate quickly, large enough to keep the request count down. */
    private const MAX_ROWS = 500;

    /** The range of the signed BIGINT columns amounts and balances go into. */
    private const BIGINT_MIN = '-9223372036854775808';

    private const BIGINT_MAX = '9223372036854775807';

    /**
     * Ownership is checked by the controller through the ImportPolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Currency codes arrive the way the file wrote them; they are compared
     * upper case, as the accounts store them.
     */
    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        $this->merge(['rows' => array_map(function ($row) {
            if (is_array($row) && is_string($row['currency_code'] ?? null)) {
                $row['currency_code'] = strtoupper(trim($row['currency_code']));
            }

            return $row;
        }, $rows)]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $isTransactions = $this->input('kind') === ImportChunkKind::Transactions->value;

        return [
            'kind' => ['required', Rule::enum(ImportChunkKind::class)],
            'position' => ['required', 'integer', 'min:0'],
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
            'rows.*.amount' => ['required', 'integer', 'min:'.self::BIGINT_MIN, 'max:'.self::BIGINT_MAX],
            'rows.*.description' => ['required', 'string', 'max:2000'],
            'rows.*.notes' => ['nullable', 'string', 'max:5000'],
            'rows.*.category_key' => ['nullable', 'string', Rule::in($this->planKeys('categories'))],
            'rows.*.external_id' => ['nullable', 'string', 'max:255'],
            'rows.*.currency_code' => ['nullable', 'string', Rule::in(app(CurrencyOptions::class)->accountCodes())],
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function balanceRules(): array
    {
        return [
            'rows.*.balance' => ['required', 'integer', 'min:'.self::BIGINT_MIN, 'max:'.self::BIGINT_MAX],
        ];
    }

    /**
     * The plan announced how many rows of each kind are coming. A chunk that
     * would take the staged rows past that, or sits past the last position
     * those rows can fill, is refused: the import would never start with it.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $import = $this->route('import');
                $kind = ImportChunkKind::tryFrom((string) $this->input('kind'));

                if ($validator->errors()->isNotEmpty() || ! $import instanceof Import || $kind === null) {
                    return;
                }

                $expected = (int) ($import->plan['expected'][$kind->value] ?? 0);
                $position = (int) $this->input('position');

                if ($position >= (int) ceil($expected / self::MAX_ROWS)) {
                    $validator->errors()->add('position', __('This import expects no more rows.'));

                    return;
                }

                $stagedElsewhere = $import->chunks()
                    ->where('kind', $kind->value)
                    ->where('position', '!=', $position)
                    ->sum('row_count');

                if ($stagedElsewhere + count((array) $this->input('rows')) > $expected) {
                    $validator->errors()->add('rows', __('This import expects no more rows.'));
                }
            },
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
