<?php

namespace App\Policies;

use App\Models\ForecastItem;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastItemPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastItem ? $model->user_id : null;
    }
}
