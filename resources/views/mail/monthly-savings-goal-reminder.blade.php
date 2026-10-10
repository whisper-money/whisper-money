<x-mail::message>
# {{ __(':days days left of :month', ['days' => $daysLeft, 'month' => $monthName]) }}

{{ __('Hi :name, you have put aside :saved of the :target you wanted to save this month for **:goal**.', ['name' => $userName, 'saved' => $savedFormatted, 'target' => $targetFormatted, 'goal' => $goal->name]) }}

<x-mail::table>
| {{ __('This month') }} | |
| :--- | ---: |
| {{ __('Saved') }} | {{ $savedFormatted }} |
| {{ __('Target') }} | {{ $targetFormatted }} |
| {{ __('Still to save') }} | **{{ $remainingFormatted }}** |
</x-mail::table>

<x-mail::button :url="route('savings-goals.show', $goal)">
{{ __('View goal') }}
</x-mail::button>

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}

<x-slot:subcopy>
{{ __('You can turn this reminder off on the goal or in [notification settings](:url).', ['url' => route('notifications.index')]) }}
</x-slot:subcopy>
</x-mail::message>
