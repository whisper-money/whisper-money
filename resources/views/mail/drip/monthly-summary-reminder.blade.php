{{-- The 3rd-of-the-month nudge. Prose, so the standard Markdown mail is the
     right shape for it — unlike the report itself, which is a designed view.

     Note what it does not say: it never claims the reader will miss out. The
     report goes out on the deadline either way, and a threat that does not
     happen only works once. --}}
<x-mail::message>
# {{ __('Your :month summary is waiting on you', ['month' => $monthName]) }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ __('We are putting your :month summary together and part of it is missing: nothing new has reached your accounts since the month closed, so what we would send you now would be short.', ['month' => $monthName]) }}

**{{ __('It goes out on :date whatever happens.', ['date' => $deadline]) }}** {{ __('Sync your banks or import your movements before then and it goes out complete instead, with the categories, the budgets and the comparison against the month before.') }}

<x-mail::button :url="route('dashboard')">
{{ __('Update my data') }}
</x-mail::button>

{{ __('And if you are done with :month and there is nothing left to add, ignore this: the summary will arrive on its own.', ['month' => $monthName]) }}

Víctor

<x-slot:subcopy>
<a href="{{ $unsubscribeUrl }}">{{ __('Turn the monthly summary off') }}</a>
</x-slot:subcopy>
</x-mail::message>
