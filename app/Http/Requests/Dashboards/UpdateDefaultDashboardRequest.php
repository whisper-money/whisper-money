<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Dashboard;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDefaultDashboardRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    /** Picking the default is editing too: it needs the CustomDashboards flag. */
    public function authorize(): bool
    {
        return $this->user()->can('create', Dashboard::class);
    }

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
