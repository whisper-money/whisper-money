<x-mail::message>
# {{ __('Welcome aboard, :name!', ['name' => $userName]) }}

{{ __("Hi! It's Victor and Álvaro, the founders of Whisper Money. We see you've already started importing your transactions - that's awesome! You're well on your way to taking control of your finances while keeping your data private.") }}

## {{ __('A Special Offer for You') }}

{{ __("As one of our early users, we want to offer you a special founder's discount. When you subscribe, you're not just getting a great app - you're directly supporting us as we continue building Whisper Money. Every subscription helps us keep the lights on and build features you actually want.") }}

<x-mail::panel>
{{ __('Use code **:code** to get **80% off** your first period (monthly or yearly!)', ['code' => $promoCode]) }}
</x-mail::panel>

{{ __('This gives you full access to all Whisper Money features:') }}

- {{ __('Unlimited transaction imports') }}
- {{ __('Automated categorization rules') }}
- {{ __('Multiple account tracking') }}
- {{ __('Your data stays yours—never shared with third parties') }}

<x-mail::button :url="config('app.url') . '/subscribe'">
{{ __('Claim Your Discount') }}
</x-mail::button>

{{ __("This code won't last forever, but more importantly, your support means the world to us. As the founders, every subscriber helps us continue building something we're passionate about.") }}

{{ __('Thanks for being part of this journey with us!') }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
