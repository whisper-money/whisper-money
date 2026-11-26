<x-mail::message>
{{-- Preheader: the line the inbox shows before the email is opened. --}}
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{{ trans_choice('{0}We spotted it this month. Take a look when you can.|{1}We spotted it a month ahead. Take a look when you can.|[2,*]We spotted it :count months ahead. Take a look when you can.', $monthsAway, ['count' => $monthsAway]) }}</div>

# {{ __('Your forecast spotted a tight month') }}

{{ __('Hi,') }}

{{ $belowMinimum
    ? __('According to your planned transactions, one of your accounts could drop below the minimum you want to keep in it in **:month**.', ['month' => $monthWithYear])
    : __('According to your planned transactions, one of your accounts could drop below zero in **:month**.', ['month' => $monthWithYear]) }}
{{ trans_choice('{0}That is this month, so it is worth a look soon.|{1}That is about a month away, so you have time to sort it out.|[2,*]That is about :count months away, so you have plenty of time to sort it out.', $monthsAway, ['count' => $monthsAway]) }}

<x-mail::panel>
**{{ __('What you can do') }}**

- {{ __('Plan a transfer from another of your accounts') }}
- {{ __('Check that month\'s payments in case one has changed') }}
- {{ __('Adjust the minimum you want to keep in that account') }}
</x-mail::panel>

<x-mail::button :url="$forecastUrl">
{{ __('See my forecast') }}
</x-mail::button>

{{ __('For your privacy, this email includes no amounts or account names. All the details are in the app.') }}

<x-slot:subcopy>
{{ __('We only tell you when a new problem shows up, never twice about the same one.') }} [{{ __('Manage forecast alerts') }}]({{ route('notifications.index') }})
</x-slot:subcopy>
</x-mail::message>
