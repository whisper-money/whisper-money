<?php

namespace App\Http\Requests\Api;

use Carbon\Carbon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MonthlySavingsTotalsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
        ];
    }

    public function month(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->string('month').'-01')->startOfMonth();
    }
}
