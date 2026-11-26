<?php

namespace App\Policies;

use App\Models\ForecastAccountSetting;
use App\Policies\Concerns\HandlesForecastOwnership;
use Illuminate\Database\Eloquent\Model;

class ForecastAccountSettingPolicy
{
    use HandlesForecastOwnership;

    /**
     * @param  ForecastAccountSetting  $model
     */
    protected function ownerId(Model $model): ?string
    {
        return $model->account?->user_id;
    }
}
