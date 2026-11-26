<?php

namespace App\Policies;

use App\Models\ForecastItemVersion;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastItemVersionPolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastItemVersion  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->forecastItem?->user_id;
    }
}
