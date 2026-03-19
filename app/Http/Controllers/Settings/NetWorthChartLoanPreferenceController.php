<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNetWorthChartLoanPreferenceRequest;
use Illuminate\Http\RedirectResponse;

class NetWorthChartLoanPreferenceController extends Controller
{
    public function update(UpdateNetWorthChartLoanPreferenceRequest $request): RedirectResponse
    {
        $request->user()->setting()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['include_loans_in_net_worth_chart' => $request->boolean('include_loans_in_net_worth_chart')]
        );

        return back();
    }
}
