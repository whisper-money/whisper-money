<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Dashboard;
use Illuminate\Foundation\Http\FormRequest;

class ReorderDashboardsRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return $this->user()->can('create', Dashboard::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['distinct', 'string', $this->userOwned('dashboards')],
        ];
    }
}
