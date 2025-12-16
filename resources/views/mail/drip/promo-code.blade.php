<x-mail::message>
# Welcome aboard, {{ $userName }}!

Hi! It's Victor, the founder of Whisper Money. I see you've already started importing your transactions - that's awesome! You're well on your way to taking control of your finances while keeping your data private.

## A Special Offer for You

As one of our early users, I want to offer you a special founder's discount. When you subscribe, you're not just getting a great app - you're directly supporting me as I continue building Whisper Money. Every subscription helps me keep the lights on and build features you actually want.

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

This code won't last forever, but more importantly, your support means the world to me. As a solo founder, every subscriber helps me continue building something I'm passionate about.

Thanks for being part of this journey with me!

Best,<br>
Víctor F,<br>
Founder of Whisper Money
</x-mail::message>
