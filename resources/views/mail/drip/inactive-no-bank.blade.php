<x-mail::message>
# {{ __('Your numbers are a week out of date, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. It's been a week since you last stopped by, and nothing has arrived on its own in the meantime: you have no bank connected, so nothing syncs in the background. Whatever Whisper Money is showing you right now is a week old.") }}

{{ __('There are two ways to fix that, and both take a couple of minutes.') }}

**{{ __('Import a CSV') }}**
{{ __('Export the transactions from your bank, then upload the file from your dashboard. We map the columns for you, and it works with most banks.') }}

**{{ __('Or connect your bank') }}**
{{ __('Your transactions then arrive on their own, every day, and we email you a summary when they do.') }} [{{ __('Connect a bank') }}]({!! route('settings.connections.index', $emailUtm + ['utm_content' => 'connect-bank']) !!})

<x-mail::button :url="route('dashboard', $emailUtm + ['utm_content' => 'dashboard'])">
{{ __('Go to Dashboard') }}
</x-mail::button>

{{ __("If something got in the way, your bank is missing, the import failed, or Whisper Money just is not what you were looking for, reply to this email and tell us. We read every reply ourselves.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}

<x-slot:subcopy>
{!! __('Don\'t want these emails? Manage notifications in [notification settings](:url).', ['url' => route('notifications.index', $emailUtm + ['utm_content' => 'notification-settings'])]) !!}
</x-slot:subcopy>
</x-mail::message>
