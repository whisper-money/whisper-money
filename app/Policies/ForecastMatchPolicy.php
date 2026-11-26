<?php

namespace App\Policies;

use App\Models\ForecastMatch;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastMatchPolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastMatch  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->forecastItem?->user_id;
    }
}
