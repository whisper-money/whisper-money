<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAccountDetailRules;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditCardDetailRequest extends FormRequest
{
    use ValidatesAccountDetailRules, ValidatesUserOwnedResources;

    /**
     * Determine if the user is authorized to make this request. Checked here,
     * before the rules run, since they read the card's stored detail.
     */
    public function authorize(): bool
    {
        $account = $this->route('account');

        return $account instanceof Account && $this->user()->can('update', $account);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->route('account');

        return $this->creditCardDetailRules($account instanceof Account ? $account->creditCardDetail : null);
    }
}
