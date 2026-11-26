<?php

namespace App\Policies;

use App\Models\ForecastEstimateAdjustment;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastEstimateAdjustmentPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastEstimateAdjustment ? $model->user_id : null;
    }
}
