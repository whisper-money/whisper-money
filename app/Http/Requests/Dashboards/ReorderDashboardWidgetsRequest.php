<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\AuthorizesDashboardUpdate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderDashboardWidgetsRequest extends FormRequest
{
    use AuthorizesDashboardUpdate;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => [
                'distinct',
                'string',
                Rule::exists('dashboard_widgets', 'id')->where('dashboard_id', $this->dashboard()->id),
            ],
        ];
    }
}
