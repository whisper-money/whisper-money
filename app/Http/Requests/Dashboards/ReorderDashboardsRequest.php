<?php

namespace App\Http\Requests\Dashboards;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Models\Dashboard;
use Illuminate\Foundation\Http\FormRequest;

class ReorderDashboardsRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    /** Ordering is editing, so it needs the paid plan like a rename. */
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
            // Ids left out keep their position.
            'ids' => ['required', 'array', 'max:'.Dashboard::MAX_PER_USER],
            'ids.*' => ['distinct', 'string', $this->userOwned('dashboards')],
        ];
    }
}
