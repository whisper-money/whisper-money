<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNetWorthChartRealEstatePreferenceRequest;
use Illuminate\Http\RedirectResponse;

class NetWorthChartRealEstatePreferenceController extends Controller
{
    public function update(UpdateNetWorthChartRealEstatePreferenceRequest $request): RedirectResponse
    {
        $request->user()->setting()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['include_real_estate_in_net_worth_chart' => $request->boolean('include_real_estate_in_net_worth_chart')]
        );

        return back();
    }
}
