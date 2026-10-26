<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConvertedAmountsRequest extends FormRequest
{
    /**
     * The most amounts one request converts: a few loaded pages of the
     * transactions table, which is the caller.
     */
    public const MAX_ITEMS = 500;

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
            'items' => ['required', 'array', 'max:'.self::MAX_ITEMS],
            'items.*.from' => ['required', 'string', 'size:3'],
            'items.*.amount' => ['required', 'integer'],
            'items.*.date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<array{from: string, amount: int, date: string}>
     */
    public function items(): array
    {
        return array_map(fn (array $item): array => [
            'from' => (string) $item['from'],
            'amount' => (int) $item['amount'],
            'date' => (string) $item['date'],
        ], array_values($this->validated('items')));
    }
}
