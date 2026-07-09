<x-mail::message>
# {{ __('You\'ve been invited to :space', ['space' => $spaceName]) }}

{{ __(':inviter has invited you to collaborate in the ":space" space on Whisper Money.', ['inviter' => $inviterName, 'space' => $spaceName]) }}

{{ __('Joining gives you access to that space\'s accounts, transactions, budgets and reports.') }}

<x-mail::button :url="$acceptUrl">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('If you don\'t have a Whisper Money account yet, you\'ll be able to create one first — just use this same email address.') }}

{{ __('If you weren\'t expecting this invitation, you can safely ignore this email.') }}

{{ __('Best,') }}<br>
{{ __('The Whisper Money team') }}

<x-mail::subcopy>
{{ __('If you\'re having trouble clicking the "Accept invitation" button, copy and paste the URL below into your web browser:') }} <span class="break-all">[{{ $acceptUrl }}]({{ $acceptUrl }})</span>
</x-mail::subcopy>
</x-mail::message>
