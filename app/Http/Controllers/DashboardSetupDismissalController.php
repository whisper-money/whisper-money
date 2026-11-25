<?php

namespace App\Http\Controllers;

use App\Services\Dashboards\SetupChecklist;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Hide" on the dashboard's "Finish setting up" card. For good: the steps are
 * suggestions, and someone who has said no to them should not be asked again.
 * Kept on the user, so it stays hidden on every device.
 */
class DashboardSetupDismissalController extends Controller
{
    public function __invoke(Request $request, SetupChecklist $checklist): Response
    {
        $checklist->dismiss($request->user());

        return response()->noContent();
    }
}
