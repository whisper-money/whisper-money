<?php

namespace App\Actions\Fortify;

use App\Enums\Locale;
use App\Enums\SignupPlan;
use App\Models\User;
use App\Services\CurrencyOptions;
use App\Services\FormatLocaleOptions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * The format locale browsers send by default, which is too common to tell
     * us where someone lives.
     */
    private const OS_DEFAULT_FORMAT_LOCALE = 'en-US';

    public function __construct(
        private FormatLocaleOptions $formatLocales,
        private CurrencyOptions $currencies,
    ) {}

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
        $acceptLanguage = request()->header('Accept-Language');
        $locale = Locale::detectFromHeader($acceptLanguage)->value;
        $requestedFormatLocale = $this->formatLocales->requestedInHeader($acceptLanguage);
        $formatLocale = $requestedFormatLocale ?? $this->formatLocales->fallbackFor($locale);
        $timezone = $this->normalizeTimezone($input['timezone'] ?? null);

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'locale' => $locale,
            // The same header read a second time, for the region rather than
            // the language: which of the two "es" they are decides where their
            // decimal separator goes.
            'format_locale' => $formatLocale,
            // And the same region a third time, for the money. Steps 4 and 5
            // quote an amount back at the user before any account exists, and
            // the column's own default would have quoted a Spaniard in dollars.
            'currency_code' => $this->inferCurrency($requestedFormatLocale, $formatLocale, $timezone),
            'timezone' => $timezone,
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
     * The currency to quote a new user in before they have told us one.
     *
     * A region the browser asked for wins, except "en-US": it is what macOS,
     * Windows and Chrome ship with, so on its own it says nothing about where
     * someone in Karachi or Madrid keeps their money. Then the timezone's
     * country, and only then whatever the language fallback guessed.
     */
    protected function inferCurrency(?string $requestedFormatLocale, string $formatLocale, ?string $timezone): string
    {
        $requestedCurrency = $requestedFormatLocale === null || $requestedFormatLocale === self::OS_DEFAULT_FORMAT_LOCALE
            ? null
            : $this->currencies->forRegion(substr($requestedFormatLocale, -2));

        return $requestedCurrency
            ?? $this->currencies->forTimezone($timezone)
            ?? $this->currencies->forFormatLocale($formatLocale);
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
