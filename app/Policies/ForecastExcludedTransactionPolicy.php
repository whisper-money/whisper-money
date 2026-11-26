<?php

namespace App\Policies;

use App\Models\ForecastExcludedTransaction;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastExcludedTransactionPolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastExcludedTransaction  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->transaction?->user_id;
    }
}
