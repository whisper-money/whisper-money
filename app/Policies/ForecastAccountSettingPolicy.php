<?php

namespace App\Policies;

use App\Models\ForecastAccountSetting;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastAccountSettingPolicy
{
    use HandlesForecastOwnership;

    protected function ownerId(Model $model): ?string
    {
        return $model instanceof ForecastAccountSetting ? $model->account?->user_id : null;
    }
}
