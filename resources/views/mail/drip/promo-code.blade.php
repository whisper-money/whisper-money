<x-mail::message>
# Welcome aboard, {{ $userName }}!

I see you've already started importing your transactions - that's awesome! You're well on your way to taking control of your finances while keeping your data private.

## A Special Offer for You

As one of our early users, I want to offer you a special founder's discount:

<x-mail::panel>
Use code **{{ $promoCode }}** to get your first month for just **$1**
</x-mail::panel>

This gives you full access to all Whisper Money features:

- Unlimited transaction imports
- Automated categorization rules
- Multiple account tracking
- End-to-end encrypted storage

<x-mail::button :url="config('app.url') . '/subscribe'">
Claim Your Discount
</x-mail::button>

This code won't last forever, so don't miss out!

Best,<br>
{{ config('app.name') }}
</x-mail::message>
