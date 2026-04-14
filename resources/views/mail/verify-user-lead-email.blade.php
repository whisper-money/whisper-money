<x-mail::message>
# {{ __('Confirm your waitlist spot') }}

{{ __('Thanks for joining the Whisper Money waiting list.') }}

{{ __('Please confirm your email address so we can reserve your place in line and unlock your referral link.') }}

<x-mail::button :url="$verificationUrl">
{{ __('Confirm my email') }}
</x-mail::button>

{{ __('If you did not request this, you can safely ignore this email.') }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}

<x-mail::subcopy>
{{ __('If you\'re having trouble clicking the "Confirm my email" button, copy and paste the URL below into your web browser:') }} <span class="break-all">[{{ $verificationUrl }}]({{ $verificationUrl }})</span>
</x-mail::subcopy>
</x-mail::message>
