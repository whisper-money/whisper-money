<?php

namespace App\Policies;

use App\Models\ForecastItemOverride;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastItemOverridePolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastItemOverride ? $model->forecastItem?->user_id : null;
    }
}
