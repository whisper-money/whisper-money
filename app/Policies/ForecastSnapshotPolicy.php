<?php

namespace App\Policies;

use App\Models\ForecastSnapshot;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastSnapshotPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastSnapshot ? $model->user_id : null;
    }
}
