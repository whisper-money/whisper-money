<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\AuthorizesDashboardUpdate;
use App\Http\Requests\Concerns\ValidatesDashboardName;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDashboardRequest extends FormRequest
{
    use AuthorizesDashboardUpdate, ValidatesDashboardName;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['name' => $this->dashboardNameRules()];
    }
}
