<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserLeadRequest;
use App\Mail\WaitlistReferralNotification;
use App\Mail\WaitlistWelcome;
use App\Models\UserLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class UserLeadController extends Controller
{
    /**
     * Store a newly created user lead.
     */
    public function store(StoreUserLeadRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $referrer = null;

        if (! empty($validated['referrer_code'])) {
            $referrer = UserLead::where('referral_code', $validated['referrer_code'])->first();
        }

        $lead = UserLead::create([
            'email' => $validated['email'],
            'referred_by_id' => $referrer?->id,
        ]);

        if ($referrer) {
            $referrer->update(['position' => max(1, $referrer->position - 10)]);
            Mail::to($referrer->email)->send(
                (new WaitlistReferralNotification($referrer->fresh()))->locale($referrer->locale),
            );
        }

        Mail::to($lead->email)->send(
            (new WaitlistWelcome($lead))->locale($lead->locale),
        );

        return to_route('waitlist.thank-you', $lead);
    }

    /**
     * Show the waitlist thank you page.
     */
    public function thankYou(UserLead $lead): Response
    {
        return Inertia::render('waitlist/thank-you', [
            'position' => $lead->position,
            'referralUrl' => $lead->referral_url,
        ]);
    }
}
