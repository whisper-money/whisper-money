<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserLeadRequest;
use App\Mail\WaitlistOvertaken;
use App\Mail\WaitlistReferralNotification;
use App\Mail\WaitlistWelcome;
use App\Models\UserLead;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
            'locale' => $validated['locale'] ?? null,
        ]);

        $lead->sendEmailVerificationNotification();

        return to_route('waitlist.check-email', $lead);
    }

    public function verify(UserLead $lead): RedirectResponse
    {
        if (! hash_equals((string) request()->query('hash'), sha1(Str::lower($lead->email)))) {
            return to_route('waitlist.check-email', $lead)
                ->withErrors(['email' => __('The verification link is invalid.')]);
        }

        if ($lead->hasVerifiedEmail()) {
            return to_route('waitlist.thank-you', $lead);
        }

        $lead->markEmailAsVerified();
        $lead->assignWaitlistSpot();

        /** @var UserLead|null $referrer */
        $referrer = $lead->referredBy?->fresh();

        if ($referrer && $referrer->hasVerifiedEmail() && $referrer->position !== null) {
            $oldPosition = $referrer->position;
            $newPosition = max(1, $oldPosition - 10);

            $referrer->update(['position' => $newPosition]);

            $overtaken = UserLead::query()
                ->whereNotNull('email_verified_at')
                ->whereBetween('position', [$newPosition, $oldPosition - 1])
                ->where('id', '!=', $referrer->id)
                ->get();

            UserLead::whereIn('id', $overtaken->pluck('id'))->increment('position');

            foreach ($overtaken as $overtakenLead) {
                Mail::to($overtakenLead->email)->send(
                    (new WaitlistOvertaken($overtakenLead->fresh()))->locale($overtakenLead->locale),
                );
            }

            Mail::to($referrer->email)->send(
                (new WaitlistReferralNotification($referrer->fresh()))->locale($referrer->locale),
            );
        }

        Mail::to($lead->email)->send(
            (new WaitlistWelcome($lead->fresh()))->locale($lead->locale),
        );

        event(new Verified($lead));

        return to_route('waitlist.thank-you', $lead);
    }

    public function checkEmail(UserLead $lead): Response|RedirectResponse
    {
        if ($lead->hasVerifiedEmail()) {
            return to_route('waitlist.thank-you', $lead);
        }

        return Inertia::render('waitlist/check-email', [
            'email' => $lead->email,
        ]);
    }

    /**
     * Show the waitlist thank you page.
     */
    public function thankYou(UserLead $lead): Response|RedirectResponse
    {
        if (! $lead->hasVerifiedEmail()) {
            return to_route('waitlist.check-email', $lead);
        }

        return Inertia::render('waitlist/thank-you', [
            'position' => $lead->position,
            'referralUrl' => $lead->referral_url,
        ]);
    }
}
