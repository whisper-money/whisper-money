{{-- The body is an operator-written Markdown file, echoed unescaped so the mail
     layout parses it as Markdown. It is deliberately never compiled as Blade:
     a file uploaded to production by hand must not be able to run code. --}}
<x-mail::message>
{!! $body !!}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
