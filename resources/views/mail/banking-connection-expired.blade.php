<x-mail::message>
# {{ __('Reconnect your :provider account', ['provider' => $providerName]) }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ __('Your :provider connection has expired, so we cannot sync new transactions until you reconnect it.', ['provider' => $providerName]) }}

<x-mail::button :url="$reconnectUrl">
{{ __('Reconnect Account') }}
</x-mail::button>

{{ __('If the button does not work, open your connection settings and reconnect :provider from there.', ['provider' => $providerName]) }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
