<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnboardingAnswersRequest extends FormRequest
{
    /**
     * The answers the onboarding collects, and the values each one accepts.
     * Mirrors the options in `step-goal.tsx` and `step-today.tsx`.
     *
     * @var array<string, list<string>>
     */
    public const CHOICES = [
        'goal' => ['keep-more', 'understand', 'debt', 'save-for'],
        'today' => ['head', 'spreadsheet', 'another-app', 'none'],
    ];

    /**
     * A ceiling rather than the slider's own range, which is in the user's
     * currency and so cannot be checked here: it only keeps a hand-rolled
     * request from writing something absurd into the column.
     */
    private const MAX_SPENDING_GUESS = 100_000_000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every answer is optional because each step saves as it is answered, and
     * a user who quits halfway leaves the ones they did give behind.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'goal' => ['sometimes', Rule::in(self::CHOICES['goal'])],
            'today' => ['sometimes', Rule::in(self::CHOICES['today'])],
            // In the minor units of the user's own currency, like every other
            // amount the app stores.
            'spending_guess' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_SPENDING_GUESS],
        ];
    }
}
