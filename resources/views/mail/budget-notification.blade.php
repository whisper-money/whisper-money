<x-mail::message>
@switch($type)
@case(\App\Enums\BudgetNotificationType::NewTransaction)
# {{ __('New transaction in ":budget"', ['budget' => $budgetName]) }}

{{ __('Hi :name, a new transaction was added to your budget:', ['name' => $userName]) }}
@break
@case(\App\Enums\BudgetNotificationType::CloseToLimit)
# {{ __('":budget" is close to its limit', ['budget' => $budgetName]) }}

{{ __('Hi :name, this budget is getting close to its limit for the current period.', ['name' => $userName]) }}
@break
@case(\App\Enums\BudgetNotificationType::OverLimit)
# {{ __('":budget" is over its limit', ['budget' => $budgetName]) }}

{{ __('Hi :name, this budget has gone over its limit for the current period.', ['name' => $userName]) }}
@break
@endswitch

@if ($transaction)
<x-mail::table>
| {{ __('Transaction') }} | |
| :--- | ---: |
| {{ $transaction->description ?: __('Transaction') }} | **{{ $transactionAmountFormatted }}** |
</x-mail::table>
@endif

<x-mail::table>
| {{ __('Budget status') }} | |
| :--- | ---: |
| {{ __('Period') }} | {{ $periodStart->isoFormat('MMM D') }} – {{ $periodEnd->isoFormat('MMM D') }} |
| {{ __('Spent') }} | {{ $spentFormatted }} |
@if ($hasLimit)
| {{ __('Limit') }} | {{ $allocatedFormatted }} |
| @if ($isOverLimit) {{ __('Over by') }} @else {{ __('Available') }} @endif | **{{ $remainingFormatted }}** |
@endif
</x-mail::table>

<x-mail::button :url="route('budgets.show', $budget)">
{{ __('View Budget') }}
</x-mail::button>

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}

<x-slot:subcopy>
{{ __('Don\'t want these emails? Manage notifications in [notification settings](:url).', ['url' => route('notifications.index')]) }}
</x-slot:subcopy>
</x-mail::message>
