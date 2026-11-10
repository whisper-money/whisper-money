<x-mail::message>
@if ($hasNewTransactions)
# {{ __('While you were away, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. You have not stopped by in two weeks, but your bank kept syncing in the background. Here is what you missed.") }}

- {{ $newTransactionsLine }}
- {{ $uncategorizedLine }}
@if ($spendingLine)
- {{ $spendingLine }}
@endif

<x-mail::button :url="route('transactions.index', $emailUtm + ['utm_content' => 'transactions'])">
{{ __('See What Came In') }}
</x-mail::button>
@else
# {{ __('Your bank has gone quiet, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. You have not stopped by in two weeks, and in that time not a single transaction has synced from your bank. That usually means the connection needs renewing: banks only let us sync for a limited time.") }}

{{ __('Reconnecting takes a couple of minutes, and everything you missed is synced in again.') }}

<x-mail::button :url="route('settings.connections.index', $emailUtm + ['utm_content' => 'reconnect'])">
{{ __('Check My Connections') }}
</x-mail::button>
@endif

{{ __('If something is not working the way you expected, reply to this email and tell us. We read every reply ourselves.') }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
