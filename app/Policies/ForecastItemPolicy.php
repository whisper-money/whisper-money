<?php

namespace App\Policies;

use App\Models\ForecastItem;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastItemPolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastItem  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->user_id;
    }
}
