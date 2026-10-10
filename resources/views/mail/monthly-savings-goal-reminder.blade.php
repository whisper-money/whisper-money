<x-mail::message>
# {{ trans_choice('{1}1 day left in :month|[2,*]:days days left in :month', $daysLeft, ['days' => $daysLeft, 'month' => $monthName]) }}

{{ trans_choice('{1}Hi :name, this monthly goal is still short of its :month target:|[2,*]Hi :name, these monthly goals are still short of their :month target:', count($goals), ['name' => $userName, 'month' => $monthName]) }}

@foreach ($goals as $goal)
- {{ __('**:goal**: you are at :percent% of it.', ['goal' => $goal['name'], 'percent' => $goal['percent']]) }}
@endforeach

<x-mail::button :url="route('budgets.index')">
{{ __('View your goals') }}
</x-mail::button>

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}

<x-slot:subcopy>
{{ __('You can turn this reminder off on each goal or in [notification settings](:url).', ['url' => route('notifications.index')]) }}
</x-slot:subcopy>
</x-mail::message>
