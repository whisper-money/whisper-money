<x-mail::message>
# {{ __('Verify your email, :name!', ['name' => $userName]) }}

{{ __("Thanks for signing up — we just need you to verify your email address to get started.") }}

{{ __("Once verified, you'll be able to set up your encryption key and start tracking your finances with full privacy.") }}

<x-mail::button :url="$verificationUrl">
{{ __('Verify Email Address') }}
</x-mail::button>

{{ __("If you didn't create a Whisper Money account, you can safely ignore this email.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}

<x-mail::subcopy>
{{ __('If you\'re having trouble clicking the "Verify Email Address" button, copy and paste the URL below into your web browser:') }} <span class="break-all">[{{ $verificationUrl }}]({{ $verificationUrl }})</span>
</x-mail::subcopy>
</x-mail::message>
