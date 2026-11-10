<?php

namespace App\Http\Controllers;

use App\Http\Requests\DismissTrialBannerRequest;
use App\Services\Subscriptions\TrialEndingBanner;
use Illuminate\Http\Response;

/**
 * Closes the trial-ending banner until its next threshold (7, 3 and 1 days
 * before the trial ends). Kept on the user, so it stays closed on every device.
 */
class TrialBannerDismissalController extends Controller
{
    public function __invoke(DismissTrialBannerRequest $request, TrialEndingBanner $banner): Response
    {
        $banner->dismiss($request->user(), $request->string('dismissal_key')->toString());

        return response()->noContent();
    }
}
