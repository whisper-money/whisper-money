<?php

namespace App\Http\Requests;

use App\Enums\CancellationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCancellationSurveyResponseRequest extends FormRequest
{
    /**
     * Whether the user has a subscription the flow can cancel is checked by
     * the controller, which needs the subscription anyway.
     */
    public function authorize(): bool
    {
        return (bool) config('subscriptions.enabled');
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(CancellationReason::class)],
            'details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function reason(): CancellationReason
    {
        return $this->enum('reason', CancellationReason::class);
    }

    public function details(): ?string
    {
        $details = trim((string) $this->string('details'));

        return $details === '' ? null : $details;
    }
}
