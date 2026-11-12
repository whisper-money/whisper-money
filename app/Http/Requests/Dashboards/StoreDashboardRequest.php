<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\ValidatesDashboardName;
use App\Models\Dashboard;
use Illuminate\Contracts\Validation\Validator;
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

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->user()->dashboards()->count() >= Dashboard::MAX_PER_USER) {
                $validator->errors()->add('name', __('You can have up to :count dashboards.', ['count' => Dashboard::MAX_PER_USER]));
            }
        }];
    }
}
