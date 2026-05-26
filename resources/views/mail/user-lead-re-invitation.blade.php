<x-mail::message>
# {{ __('Still interested in Whisper Money?') }}

{{ __('Hey there!') }}

{{ __('We sent you an invitation to Whisper Money, but it looks like you have not created your account yet.') }}

{{ __('If privacy-first personal finance still sounds useful, your early access is still ready. Whisper Money helps you import transactions, organize spending, and understand your money without selling or sharing your financial data.') }}

<x-mail::button :url="$signupUrl">
{{ __('Create your account') }}
</x-mail::button>

@if ($promoCodeMonthly || $promoCodeYearly)
{{ __('Your launch codes are still available:') }}

@if ($promoCodeMonthly)
- {{ __('Monthly') }}: `{{ $promoCodeMonthly }}`
@endif
@if ($promoCodeYearly)
- {{ __('Yearly') }}: `{{ $promoCodeYearly }}`
@endif
@endif

{{ __('No pressure—if now is not the right time, you can ignore this email.') }}

{{ __('Questions or feedback? Reply to this email. We read every message personally.') }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
