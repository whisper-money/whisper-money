<x-mail::message>
# {{ __('Your bank connections were disconnected') }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ trans_choice('We disconnected your bank connection to keep your account on free access. Automatic bank sync is now paused, but all your accounts, transactions, and balances remain in Whisper Money.|We disconnected your :count bank connections to keep your account on free access. Automatic bank sync is now paused, but all your accounts, transactions, and balances remain in Whisper Money.', $removedConnectionsCount, ['count' => $removedConnectionsCount]) }}

{{ __('You can continue using the app on the free plan, and you can reconnect your bank later if you upgrade again.') }}

<x-mail::button :url="route('dashboard')">
{{ __('Go to Dashboard') }}
</x-mail::button>

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
