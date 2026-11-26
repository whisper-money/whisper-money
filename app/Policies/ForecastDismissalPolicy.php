<?php

namespace App\Policies;

use App\Models\ForecastDismissal;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastDismissalPolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastDismissal  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->user_id;
    }
}
