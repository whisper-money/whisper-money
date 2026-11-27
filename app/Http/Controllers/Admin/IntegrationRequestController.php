<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateIntegrationRequestStatusRequest;
use App\Models\IntegrationRequest;
use Illuminate\Http\RedirectResponse;

class IntegrationRequestController extends Controller
{
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
