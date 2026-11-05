{{-- Black Friday 2026, second and last send, on the day the code expires. Same
     audience as black-friday-2026 under a new identifier, so everyone still in
     it gets this one too. Scheduled in routes/console.php for Tuesday 1 December
     2026 at 09:00 Europe/Madrid. The subject doubles as the lang/es.json key:

     php artisan email:update black-friday-last-day-2026 --audience=unsubscribed --subject="Last day: 40% off ends tonight" --exclude-demo

     The audience is recomputed when the command runs, so anyone who subscribed
     after the first email has dropped out of it on their own. "Tonight" is
     23:59 Europe/Madrid, when BLACKFRIDAY40 expires in Stripe: sent any later
     than the morning of 1 December, the heading is wrong. --}}
<x-mail::message>
# {{ __('Last day for 40% off') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('The Black Friday discount ends tonight at 23:59 (Madrid time). This is the last email we will send you about it: after tonight the code stops working and the price goes back to normal.') }}

@include('mail.partials.black-friday-2026-offer')

<x-mail::button :url="route('subscribe')">
{{ __('Use my 40% off') }}
</x-mail::button>

{{ __('And if the free plan is what works for you, stay on it. It is not going anywhere, and we are glad to have you here either way.') }}

{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
