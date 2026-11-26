<?php

namespace App\Policies;

use App\Models\ForecastMatch;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastMatchPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastMatch ? $model->forecastItem?->user_id : null;
    }
}
