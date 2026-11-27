<?php

namespace App\Policies;

use App\Models\ForecastRecurringDrop;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastRecurringDropPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastRecurringDrop ? $model->user_id : null;
    }
}
