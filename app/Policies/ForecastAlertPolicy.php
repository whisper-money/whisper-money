<?php

namespace App\Policies;

use App\Models\ForecastAlert;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastAlertPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastAlert ? $model->user_id : null;
    }
}
