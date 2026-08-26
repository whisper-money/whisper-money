<x-mail::message>
# {{ __('Your trial ends in 3 days, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. A quick heads-up so nothing catches you by surprise: your free trial ends on :date, and we'll charge :amount to the card you signed up with.", ['date' => $trialEndsAtLocal->isoFormat('LL'), 'amount' => $amount]) }}

{{ __('If you want to carry on, there is nothing to do. Just make sure the card has enough on it that day: a first charge that gets declined is by far the most common reason an account ends up cut off, and we would rather you never find out that way.') }}

<x-mail::button :url="route('settings.billing.portal', $emailUtm + ['utm_content' => 'billing-portal'])">
{{ __('Update card or cancel') }}
</x-mail::button>

{{ __('That link takes you to your billing details, where you can change the card or cancel. Cancel before :date and you will not be charged anything at all.', ['date' => $trialEndsAtLocal->isoFormat('LL')]) }}

{{ __("If something didn't work for you, or you're not sure Whisper Money is worth it yet, reply to this email and tell us. We read every reply ourselves.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
