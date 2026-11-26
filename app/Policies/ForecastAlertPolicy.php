<?php

namespace App\Policies;

use App\Models\ForecastAlert;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastAlertPolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastAlert  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->user_id;
    }
}
