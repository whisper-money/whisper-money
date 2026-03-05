<x-mail::message>
# {{ __('Hey :name, is everything okay?', ['name' => $userName]) }}

{{ __("Hi! We're Victor and Álvaro, the founders of Whisper Money. We noticed you signed up but haven't completed your setup yet. We wanted to check in personally and see if something went wrong or if you need any help.") }}

{{ __("We know setting up a new app can be overwhelming, and we want to make sure you have everything you need to get started.") }}

## {{ __('Common Questions') }}

**{{ __('Not sure how to import transactions?') }}**
{{ __('We support CSV imports from most banks. Just export your transactions and upload them - it takes less than a minute. If your bank format is different, just let us know and we can help.') }}

**{{ __('Want to connect your bank directly?') }}**
{{ __('We support Open Banking connections so your transactions sync automatically. Head to Settings > Connections to get started.') }}

**{{ __('Something not working?') }}**
{{ __("Just reply to this email and let us know what's happening. We personally read every response and will help you get started.") }}

<x-mail::button :url="config('app.url') . '/onboarding'">
{{ __('Continue Setup') }}
</x-mail::button>

{{ __("Looking forward to hearing from you! And if you decide Whisper Money isn't for you, that's totally fine - but we'd love to know why so we can improve.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
