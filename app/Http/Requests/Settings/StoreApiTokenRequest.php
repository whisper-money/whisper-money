<?php

namespace App\Http\Requests\Settings;

use App\Enums\ApiTokenExpiry;
use App\Enums\PlanFeature;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends StoreAccessTokenRequest
{
    /**
     * Only a Pro account may create an API token: the API answers anyone else
     * with a 403, so a token minted on a free plan would be useless.
     */
    public function authorize(): bool
    {
        return $this->user()->canUseFeature(PlanFeature::ApiAccess);
    }

    /**
     * The token's lifetime; 90 days when it is left out.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function surfaceRules(): array
    {
        return ['expiry' => ['nullable', Rule::enum(ApiTokenExpiry::class)]];
    }

    /**
     * The lifetime picked, 90 days when none was sent.
     */
    public function expiry(): ApiTokenExpiry
    {
        return ApiTokenExpiry::tryFrom((string) $this->validated('expiry')) ?? ApiTokenExpiry::Default;
    }
}
