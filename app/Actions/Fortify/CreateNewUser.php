<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\LandingAuthOverrideService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private LandingAuthOverrideService $landingAuthOverrideService) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if ($this->landingAuthOverrideService->authButtonsHidden(request())) {
            abort(404);
        }

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
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'locale' => $this->detectLocaleFromRequest(),
            'timezone' => $this->normalizeTimezone($input['timezone'] ?? null),
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

    /**
     * Detect locale from Accept-Language header.
     */
    protected function detectLocaleFromRequest(): string
    {
        $acceptLanguage = request()->header('Accept-Language', '');

        // Check if Spanish is preferred
        if (preg_match('/^es(-|,|;)/i', $acceptLanguage) || $acceptLanguage === 'es') {
            return 'es';
        }

        return 'en';
    }
}
