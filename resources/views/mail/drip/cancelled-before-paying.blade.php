<x-mail::message>
# {{ __('Hey :name, what was missing?', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. We saw your subscription ended before your first payment, and we wanted to ask you personally: what was missing?") }}

{{ __('Was there a feature you needed? Did something not work the way you expected? Was it the price? Any reason helps us, even a short one.') }}

{{ __('Just reply to this email and tell us in a line or two. It comes straight to our inbox, and we read and answer every message ourselves.') }}

{{ __('Thank you for giving Whisper Money a try.') }}

{{ __('Best,') }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
