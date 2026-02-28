<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateChartColorSchemeRequest;
use Illuminate\Http\RedirectResponse;

class ChartColorSchemeController extends Controller
{
    public function update(UpdateChartColorSchemeRequest $request): RedirectResponse
    {
        $request->user()->setting()->updateOrCreate(
            ['user_id' => $request->user()->id],
            ['chart_color_scheme' => $request->validated('chart_color_scheme')]
        );

        return back();
    }
}
