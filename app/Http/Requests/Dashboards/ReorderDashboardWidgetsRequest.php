<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\AuthorizesDashboardUpdate;
use App\Models\Dashboard;
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
            // Ids left out keep their position.
            'ids' => ['required', 'array', 'max:'.Dashboard::MAX_WIDGETS],
            'ids.*' => [
                'distinct',
                'string',
                Rule::exists('dashboard_widgets', 'id')->where('dashboard_id', $this->dashboard()->id),
            ],
        ];
    }
}
