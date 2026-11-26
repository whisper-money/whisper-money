<?php

namespace App\Policies;

use App\Models\ForecastExcludedTransaction;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastExcludedTransactionPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastExcludedTransaction ? $model->transaction?->user_id : null;
    }
}
