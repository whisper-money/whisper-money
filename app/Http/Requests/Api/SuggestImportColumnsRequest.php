<?php

namespace App\Http\Requests\Api;

use App\Enums\ImportConfigType;
use App\Services\Ai\AiImportMappingGate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The layout of a file being imported, as sent to the AI for column-mapping
 * suggestions. Only each column's header and computed shape are accepted, never
 * a cell value: anything else in the payload is refused, which is what keeps
 * the user's data out of the request.
 */
class SuggestImportColumnsRequest extends FormRequest
{
    public const int MAX_COLUMNS = 60;

    public const int MAX_LENGTH = 120;

    private const array ALLOWED_KEYS = ['kind', 'columns'];

    public function authorize(AiImportMappingGate $gate): bool
    {
        return $gate->allows($this->user());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(ImportConfigType::class)],
            'columns' => ['required', 'list', 'max:'.self::MAX_COLUMNS],
            'columns.*' => ['required', 'array:header,shape'],
            'columns.*.header' => ['present', 'nullable', 'string', 'max:'.self::MAX_LENGTH],
            'columns.*.shape' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_diff(array_keys($this->all()), self::ALLOWED_KEYS) as $key) {
                    $validator->errors()->add((string) $key, __('This field is not accepted.'));
                }
            },
        ];
    }

    public function kind(): ImportConfigType
    {
        return ImportConfigType::from($this->validated('kind'));
    }

    /**
     * @return list<array{header: ?string, shape: string}>
     */
    public function columns(): array
    {
        return $this->validated('columns');
    }
}
