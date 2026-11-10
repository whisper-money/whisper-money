<x-mail::message>
# {{ __('Your annual plan renews soon, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. A quick heads-up so nothing catches you by surprise: your annual plan renews on :date, and we'll charge :amount to your saved payment method.", ['date' => $renewsAtLocal->isoFormat('LL'), 'amount' => $amount]) }}

@if (count($recap) > 0)
## {{ __('Your year with Whisper Money') }}

{{ __("Here's what you've built in Whisper Money so far:") }}

@foreach ($recap as $line)
- {{ $line }}
@endforeach

@endif
{{ __('If you want to carry on, there is nothing to do. To change your plan, update your card or cancel, head to your billing settings before :date.', ['date' => $renewsAtLocal->isoFormat('LL')]) }}

<x-mail::button :url="route('settings.billing', $emailUtm + ['utm_content' => 'billing-settings'])">
{{ __('Manage billing') }}
</x-mail::button>

{{ __("If something isn't working for you, reply to this email and tell us. We read every reply ourselves.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
