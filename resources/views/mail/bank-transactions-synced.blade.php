<x-mail::message>
# {{ __('New transactions synced, :name!', ['name' => $userName]) }}

{{ __('We just synced new transactions from your connected banks:') }}

@foreach ($transactionsPerBank as $bankName => $count)
- **{{ $bankName }}** - {{ trans_choice(':count new transaction|:count new transactions', $count, ['count' => $count]) }}
@endforeach

<x-mail::button :url="route('transactions.index')">
{{ __('View Transactions') }}
</x-mail::button>

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
