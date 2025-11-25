<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserLeadRequest;
use App\Models\UserLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class UserLeadController extends Controller
{
    /**
     * Store a newly created user lead and redirect to external form.
     */
    public function store(StoreUserLeadRequest $request): RedirectResponse|Response
    {
        $validated = $request->validated();
        UserLead::create($validated);

        $redirectUrl = config('landing.lead_redirect_url');

        if ($redirectUrl) {
            $urlWithEmail = $redirectUrl.'?email='.urlencode($validated['email']);

            return response('', 409)->header('X-Inertia-Location', $urlWithEmail);
        }

        return to_route('home')->with('success', 'Thank you for your interest!');
    }
}
