<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserLeadRequest;
use App\Models\UserLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class UserLeadController extends Controller
{
    /**
     * Store a newly created user lead and redirect to external form.
     */
    public function store(StoreUserLeadRequest $request): RedirectResponse|Response
    {
        $validated = $request->validated();
        $lead = UserLead::create($validated);

        $this->trackLeadCreatedEvent($validated['email']);

        $redirectUrl = config('landing.lead_redirect_url');

        if ($redirectUrl) {
            $urlWithEmail = $redirectUrl.'?email='.urlencode($validated['email']);

            return response('', 409)->header('X-Inertia-Location', $urlWithEmail);
        }

        return to_route('home')->with('success', 'Thank you for your interest!');
    }

    protected function trackLeadCreatedEvent(string $email): void
    {
        $eventUuid = config('landing.lead_funnel_event_uuid');

        if (! $eventUuid || app()->environment('local', 'testing')) {
            return;
        }

        Http::withoutVerifying()
            ->timeout(5)
            ->post("https://metricswave.com/webhooks/{$eventUuid}", [
                'step' => 'Lead Created',
            ]);
    }
}
