<x-mail::message>
# {{ __('Your plan resumes on :date, :name', ['date' => $resumesOn, 'name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. You paused your plan, and the pause ends on :date. That day Standard comes back on its own, and we'll charge :amount to your card.", ['date' => $resumesOn, 'amount' => $amount]) }}

{{ __('Bank sync picks up where it left off, and everything you set up is still there.') }}

{{ __('If you would rather not carry on, you can cancel from your billing page before :date and nothing will be charged.', ['date' => $resumesOn]) }}

<x-mail::button :url="route('settings.billing', $emailUtm + ['utm_content' => 'billing'])">
{{ __('Manage my plan') }}
</x-mail::button>

{{ __("If something didn't work for you, reply to this email and tell us. We read every reply ourselves.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
