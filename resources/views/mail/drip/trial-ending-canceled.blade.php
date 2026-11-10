<x-mail::message>
# {{ __('Your Pro trial ends on :date, :name', ['date' => $trialEndsAtLocal->isoFormat('LL'), 'name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. You canceled your plan, so you won't be charged anything. We just wanted you to know what happens on :date, when your trial ends.", ['date' => $trialEndsAtLocal->isoFormat('LL')]) }}

{{ __('From that day, bank sync, AI categorization and the rest of Pro stop. Your movements no longer arrive on their own.') }}

@if ($stats['accountsCount'] > 0 || $stats['transactionsCount'] > 0)
{{ __("Here's what you've set up so far, and it stays yours:") }}

@if ($stats['accountsCount'] > 0)
- {{ trans_choice(':count account|:count accounts', $stats['accountsCount'], ['count' => \Illuminate\Support\Number::format($stats['accountsCount'], locale: app()->getLocale())]) }}
@endif
@if ($stats['transactionsCount'] > 0)
- {{ trans_choice(':count transaction|:count transactions', $stats['transactionsCount'], ['count' => \Illuminate\Support\Number::format($stats['transactionsCount'], locale: app()->getLocale())]) }}
@endif
@endif

{{ __('If you canceled just to be safe, the button below takes you to your billing page, where one click keeps Pro. Your first charge would then be on :date.', ['date' => $trialEndsAtLocal->isoFormat('LL')]) }}

<x-mail::button :url="route('settings.billing', $emailUtm + ['utm_content' => 'keep-plan'])">
{{ __('Keep my plan') }}
</x-mail::button>

{{ __("If something didn't work for you, reply to this email and tell us. We read every reply ourselves.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
