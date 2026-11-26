<?php

namespace App\Policies;

use App\Models\ForecastItemVersion;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastItemVersionPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastItemVersion ? $model->forecastItem?->user_id : null;
    }
}
