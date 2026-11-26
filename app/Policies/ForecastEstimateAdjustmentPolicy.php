<?php

namespace App\Policies;

use App\Models\ForecastEstimateAdjustment;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastEstimateAdjustmentPolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastEstimateAdjustment  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->user_id;
    }
}
