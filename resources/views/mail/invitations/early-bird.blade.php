<x-mail::message>
# {{ __("You're in early — months on us 🎉") }}

{{ __('Hey there!') }}

{{ __("We're Víctor and Álvaro, the founders of Whisper Money. Now Whisper Money is open. As a thank you, your first months are on us.") }}

## {{ __('Your early-access gift') }}

- {{ __('**Monthly plan**: 60 days free') }}
- {{ __('**Yearly plan**: 90 days free on your first year') }}

{{ __('Pick whichever plan fits you, paste the matching code at checkout, and you are set.') }}

<x-mail::panel>
**{{ __('Monthly code') }}:** `{{ $promoCodeMonthly }}`<br>
**{{ __('Yearly code') }}:** `{{ $promoCodeYearly }}`<br>
{{ __('Each code is single-use and tied to your email — please keep them private.') }}
</x-mail::panel>

<x-mail::button :url="$signupUrl">
{{ __('Create your account') }}
</x-mail::button>

## {{ __('What you get') }}

- {{ __('Unlimited transaction imports') }}
- {{ __('Automated categorization rules') }}
- {{ __('Multiple account tracking') }}
- {{ __('Your data stays yours—never shared with third parties') }}
- {{ __('Mobile app (iOS & Android)') }}

{{ __('**Have feedback? Questions? Issues?** Just hit reply to this email. We read and respond to every message personally.') }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
