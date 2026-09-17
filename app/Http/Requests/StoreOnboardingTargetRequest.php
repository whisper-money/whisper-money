<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreOnboardingTargetRequest extends FormRequest
{
    /**
     * A ceiling rather than the stepper's own range, which is in the user's
     * currency and derived from their spending, so it cannot be checked here:
     * it only keeps a hand-rolled request from writing something absurd.
     */
    private const MAX_TARGET = 100_000_000;

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
            // In the minor units of the user's own currency, like every other
            // amount the app stores.
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TARGET],
            'warn' => ['required', 'boolean'],
        ];
    }
}
