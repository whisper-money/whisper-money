<?php

namespace App\Http\Requests\Concerns;

use App\Models\Dashboard;

/**
 * For requests on a `{dashboard}` route: only its owner may change it. Checked
 * here rather than in the controller so a stranger gets the 403 before any
 * validation message about a dashboard that is not theirs.
 */
trait AuthorizesDashboardUpdate
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->dashboard());
    }

    protected function dashboard(): Dashboard
    {
        /** @var Dashboard */
        return $this->route('dashboard');
    }
}
