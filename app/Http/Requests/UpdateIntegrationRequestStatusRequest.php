<?php

namespace App\Http\Requests;

use App\Enums\IntegrationRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIntegrationRequestStatusRequest extends FormRequest
{
    /**
     * The route is already behind the `admin` middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(IntegrationRequestStatus::class)],
            'comment' => [
                Rule::requiredIf(fn (): bool => $this->enum('status', IntegrationRequestStatus::class)?->requiresComment() ?? false),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.required' => __('Tell users why this integration is not doable.'),
        ];
    }

    /**
     * The status the request moves to; validation has already checked it is one.
     */
    public function status(): IntegrationRequestStatus
    {
        return IntegrationRequestStatus::from($this->validated('status'));
    }
}
