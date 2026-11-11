<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDefaultDashboardRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    /**
     * Null picks the system dashboard again.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'dashboard_id' => ['present', 'nullable', 'string', $this->userOwned('dashboards')],
        ];
    }
}
