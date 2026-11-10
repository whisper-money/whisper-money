<x-mail::message>
# {{ __('Your free trial ends on :date, :name', ['date' => $trialEndsAtLocal->isoFormat('LL'), 'name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. A quick heads-up so nothing catches you by surprise: your free trial ends on :date, and we'll charge :amount to the card you signed up with.", ['date' => $trialEndsAtLocal->isoFormat('LL'), 'amount' => $amount]) }}

@if ($progress !== null)
**{{ __('In your trial so far') }}**

- {{ __(':count movements synced from your bank', ['count' => \Illuminate\Support\Number::format($progress['synced'], locale: app()->getLocale())]) }}
@if ($progress['categorizedPercent'] > 0)
- {{ __(':percent% of them sorted into a category', ['percent' => $progress['categorizedPercent']]) }}
@endif
@endif

**{{ __('If you keep it') }}** — {{ __('Your bank keeps syncing and new movements arrive sorted.') }}

**{{ __('If you cancel') }}** — {{ __('You are not charged. Everything you imported stays yours; syncing and the AI stop.') }}

{{ __('If you want to carry on, there is nothing to do. Just make sure the card has enough on it that day: a first charge that gets declined is by far the most common reason an account ends up cut off, and we would rather you never find out that way.') }}

<x-mail::button :url="route('dashboard', $emailUtm + ['utm_content' => 'open-app'])">
{{ __('Open Whisper Money') }}
</x-mail::button>

[{{ __('Change or cancel your plan') }}]({{ route('settings.billing.portal', $emailUtm + ['utm_content' => 'billing-portal']) }}) · {{ __('Cancel before :date and you will not be charged anything at all.', ['date' => $trialEndsAtLocal->isoFormat('LL')]) }}

{{ __("If something didn't work for you, or you're not sure Whisper Money is worth it yet, reply to this email and tell us. We read every reply ourselves.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
