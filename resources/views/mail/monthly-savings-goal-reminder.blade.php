<x-mail::message>
# {{ trans_choice('{1}1 day left in :month|[2,*]:days days left in :month', $daysLeft, ['days' => $daysLeft, 'month' => $monthName]) }}

{{ __('Hi :name, you are at :percent% of your :month target for **:goal**.', ['name' => $userName, 'percent' => $percent, 'month' => $monthName, 'goal' => $goal->name]) }}

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
