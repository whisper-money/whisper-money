<?php

namespace App\Actions\Fortify;

use App\Models\User;
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
        if (config('landing.hide_auth_buttons', false) && ! request()->boolean('force')) {
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
            'timezone' => ['nullable', 'string', 'timezone'],
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'locale' => $this->detectLocaleFromRequest(),
            'timezone' => $input['timezone'] ?? null,
        ]);

        if (! config('mail.email_verification_enabled')) {
            $user->markEmailAsVerified();
        }

        return $user;
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
