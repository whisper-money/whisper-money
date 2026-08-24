<?php

namespace App\Actions\Fortify;

use App\Enums\Locale;
use App\Enums\SignupPlan;
use App\Models\User;
use App\Services\Subscriptions\PriceExperiment;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        abort_if(! config('auth.registration_enabled'), 403);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'timezone' => ['nullable', 'string', 'max:255'],
            'signup_plan' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $signupPlan = SignupPlan::fromRequest($input['signup_plan'] ?? null);

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'locale' => Locale::detectFromHeader(request()->header('Accept-Language'))->value,
            'timezone' => $this->normalizeTimezone($input['timezone'] ?? null),
            // Freeze the arm this visitor was quoted as an anonymous browser, so
            // the price on the landing is the price at checkout. Null when they
            // arrived without a cookie: those users pay the control price.
            'price_arm' => PriceExperiment::sanitize(request()->cookie(PriceExperiment::COOKIE)),
            // Which pricing card they came from, so onboarding can hide the paid
            // options from someone who signed up for the free plan.
            'signup_plan' => $signupPlan?->value,
            // Someone who picked the free plan has already answered the paywall
            // question, so skip bouncing them through it at the end of onboarding.
            'paywall_seen_at' => $signupPlan === SignupPlan::Free ? now() : null,
        ]);

        if (! config('mail.email_verification_enabled')) {
            $user->markEmailAsVerified();
        }

        return $user;
    }

    /**
     * Normalize a browser-detected timezone, discarding identifiers PHP does
     * not recognize so a hidden auto-detected field can never block registration.
     */
    protected function normalizeTimezone(?string $timezone): ?string
    {
        if ($timezone === null || $timezone === '') {
            return null;
        }

        if (! in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            return null;
        }

        return $timezone;
    }
}
