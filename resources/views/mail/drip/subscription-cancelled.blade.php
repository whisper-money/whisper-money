<x-mail::message>
# {{ __("We're sorry to see you go, :name", ['name' => $userName]) }}

{{ __("Hi! It's Victor and Álvaro, the founders of Whisper Money. We noticed you've cancelled your subscription, and we wanted to reach out personally.") }}

{{ __('First, thank you for giving Whisper Money a try. We hope it helped you get a better handle on your finances while keeping your data private.') }}

## {{ __('Before you go...') }}

{{ __("If there's anything that didn't work well for you, or if you have suggestions for improvement, we'd genuinely love to hear about it. As the founders, your feedback is invaluable in making Whisper Money better.") }}

{{ __("If you'd like to come back, here's a special offer just for you:") }}

<x-mail::panel>
{{ __('Use code **CONTINUE50** to get **50% off** all current and future payments - works for both monthly and yearly subscriptions.') }}
</x-mail::panel>

<x-mail::button :url="config('app.url') . '/subscribe'">
{{ __('Reactivate Your Subscription') }}
</x-mail::button>

{{ __('Your data and settings will be preserved, so you can pick up right where you left off.') }}

{{ __('If you have any questions or just want to chat, simply reply to this email. We read and respond to every message personally.') }}

{{ __('Thanks again for being part of this journey!') }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
