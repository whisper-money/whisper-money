<x-mail::message>
# {{ __('Your referral made you a founder 🎁') }}

{{ __('Hey there!') }}

{{ __("We're Víctor and Álvaro, the founders of Whisper Money. Someone you invited landed in our top 10 — that makes you a Whisper Money founder too.") }}

## {{ __("Your gift: Whisper Money Pro, free forever") }}

{{ __("Because you helped one of our first 10 join us, you get **Whisper Money Pro free, forever**. No trials, no expirations. Use the code below at checkout.") }}

<x-mail::panel>
**{{ __('Your personal code') }}:** `{{ $promoCodeMonthly }}`<br>
{{ __('Single-use. Works on monthly or yearly plans. Tied to your email — please keep it private.') }}
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

{{ __('Thanks for spreading the word.') }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
