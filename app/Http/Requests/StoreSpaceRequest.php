<?php

namespace App\Http\Requests;

use App\Features\Spaces;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Pennant\Feature;

class StoreSpaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && Feature::for($this->user())->active(Spaces::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
