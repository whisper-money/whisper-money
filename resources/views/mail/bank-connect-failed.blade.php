<x-mail::message>
# {{ __(':bank cannot be connected right now, and it was not your fault', ['bank' => $bankName]) }}

{{ __('Hi :name,', ['name' => $userName]) }}

{{ __('You tried to connect :bank to Whisper Money and it did not work. We looked into it, and the failure is in the bank\'s own connection service: it rejects the request before we ever get to your accounts. It is failing for everyone who tries it, not just for you, so there was nothing you could have done differently.', ['bank' => $bankName]) }}

{{ __('There is no point trying again for now. Every attempt ends the same way until the bank fixes its side, and we would rather tell you that than let you keep trying.') }}

{{ __('We have reported it to our banking provider and we are pushing to get :bank working. As soon as it connects, we will email you so you do not have to keep checking.', ['bank' => $bankName]) }}

{{ __('In the meantime you can still track that account here: add it manually, or import a file exported from your bank. Everything else in Whisper Money works as usual.') }}

<x-mail::button :url="route('accounts.list')">
{{ __('Add the account manually') }}
</x-mail::button>

{{ __('Sorry for the wasted time, and thank you for the patience.') }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
