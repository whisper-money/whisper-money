<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateIntegrationRequestStatusRequest;
use App\Models\IntegrationRequest;
use App\Support\Admin\AdminIntegrationRequestRow;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationRequestController extends Controller
{
    /**
     * Every integration request, whatever its status, most voted first.
     */
    public function index(): Response
    {
        return Inertia::render('admin/integration-requests', [
            'integrationRequests' => AdminIntegrationRequestRow::query()
                ->get()
                ->map(AdminIntegrationRequestRow::from(...))
                ->all(),
        ]);
    }

    /**
     * The web form of `integration-requests:review`: move one request to a
     * new status, with the public comment the status allows.
     */
    public function update(UpdateIntegrationRequestStatusRequest $request, IntegrationRequest $integrationRequest): RedirectResponse
    {
        $integrationRequest->review($request->status(), $request->validated('comment'));

        return back();
    }
}
