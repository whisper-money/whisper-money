<x-mail::message>
# {{ __('Renew your :provider connection', ['provider' => $providerName]) }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ __('Banks only let us sync for a limited time, and the permission you gave us for :provider runs out on :date. Once it does, new transactions stop arriving until you renew it.', ['provider' => $providerName, 'date' => $expiresOn]) }}

{{ __('You can renew it right now, before it runs out, and nothing will stop syncing. It takes the same two minutes as connecting the bank did.') }}

<x-mail::button :url="$reconnectUrl">
{{ __('Renew Connection') }}
</x-mail::button>

{{ __('If the button does not work, open your connection settings and reconnect :provider from there.', ['provider' => $providerName]) }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
