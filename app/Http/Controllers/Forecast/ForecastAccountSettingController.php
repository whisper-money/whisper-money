<?php

namespace App\Http\Controllers\Forecast;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forecast\UpdateForecastAccountSettingRequest;
use App\Models\Account;
use App\Models\ForecastAccountSetting;
use Illuminate\Http\RedirectResponse;

/**
 * The wanted minimum, the yearly rate and whether the forecast covers an
 * account. An account left out of the forecast has no page of its own any
 * more, so leaving out the one being looked at lands on the forecast's
 * default account; any other change stays where the user was.
 */
class ForecastAccountSettingController extends Controller
{
    public function update(UpdateForecastAccountSettingRequest $request, Account $account): RedirectResponse
    {
        $settings = $request->settings();

        ForecastAccountSetting::query()->updateOrCreate(['account_id' => $account->id], $settings);

        if (($settings['included'] ?? true) === false && $this->cameFrom($account)) {
            return to_route('forecast.index');
        }

        return back();
    }

    /**
     * Whether the user changed the account from its own forecast page.
     */
    private function cameFrom(Account $account): bool
    {
        parse_str((string) parse_url(url()->previous(), PHP_URL_QUERY), $query);

        return ($query['account'] ?? null) === $account->id;
    }
}
