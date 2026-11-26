<?php

namespace App\Policies;

use App\Models\ForecastItemOverride;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastItemOverridePolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastItemOverride  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->forecastItem?->user_id;
    }
}
