<?php

namespace App\Http\Requests\Concerns;

trait ValidatesDashboardName
{
    /**
     * @return list<string>
     */
    protected function dashboardNameRules(): array
    {
        return ['required', 'string', 'max:60'];
    }
}
