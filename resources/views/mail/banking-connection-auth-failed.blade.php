<x-mail::message>
# {{ __('Your :provider connection needs attention', ['provider' => $providerName]) }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ __('We were unable to sync your :provider connection because your credentials appear to have expired or been revoked.', ['provider' => $providerName]) }}

{{ __('To fix this, generate a new API token from your :provider account and update it in your connection settings.', ['provider' => $providerName]) }}

<x-mail::button :url="route('settings.connections.index')">
{{ __('Update Credentials') }}
</x-mail::button>

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
