<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\ValidatesDashboardName;
use App\Models\Dashboard;
use Illuminate\Foundation\Http\FormRequest;

class StoreDashboardRequest extends FormRequest
{
    use ValidatesDashboardName;

    public function authorize(): bool
    {
        return $this->user()->can('create', Dashboard::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['name' => $this->dashboardNameRules()];
    }
}
