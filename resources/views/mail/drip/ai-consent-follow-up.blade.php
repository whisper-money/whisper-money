<x-mail::message>
# {{ __('Your AI assistant is hard at work, :name', ['name' => $userName]) }}

{{ __('A couple of days ago you turned on AI-powered insights in Whisper Money. We wanted to check in and show you what it has been doing for you behind the scenes.') }}

## {{ __('Your transactions, categorised automatically') }}

{{ __('Instead of sorting every transaction by hand, our AI suggests a category for each one — so your spending breakdown stays accurate and up to date with almost no effort from you.') }}

<x-mail::button :url="route('transactions.index', ['category_source' => 'ai'])">
{{ __('See your categorised transactions') }}
</x-mail::button>

## {{ __('Private by design, always') }}

{{ __('Turning on AI never changes who owns your data — you do. We only use it to help you understand your own finances, and you can revoke this consent at any time from your settings.') }}

{{ __('If you have any questions, **just reply to this email**. We personally read every message.') }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
