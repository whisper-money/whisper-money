<x-mail::message>
# {{ __('Import your history from your computer') }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ __('Here is the link you asked for. Open it on your computer, download your movements from your bank\'s website, and drop the file in. We take you straight back to the account you were importing into.') }}

<x-mail::button :url="$url">
{{ __('Import my movements') }}
</x-mail::button>

{{ __('The link works for :days days and only for you: you may be asked to log in first.', ['days' => $days]) }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
