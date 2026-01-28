<?php

namespace App\Http\Controllers;

use App\Console\Commands\Concerns\ResolvesFeatures;
use App\Http\Requests\FeatureActivationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Pennant\Feature;

class FeatureActivationController extends Controller
{
    use ResolvesFeatures;

    public function __invoke(FeatureActivationRequest $request, string $feature): RedirectResponse
    {
        $featureClass = $this->resolveFeatureClass($feature);

        if (! $featureClass) {
            abort(404);
        }

        $user = User::where('email', $request->validated('email'))->first();

        if ($user) {
            Feature::for($user)->activate($featureClass);
        }

        return redirect()->route('dashboard');
    }
}
