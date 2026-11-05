{{-- Black Friday 2026, first of two sends, to users with no subscription and
     no trial who have not turned off "Product news and offers". Scheduled in
     routes/console.php for Friday 27 November 2026 at 09:00 Europe/Madrid,
     so nobody has to remember to run it. The scheduled call is the same as
     running by hand, and the subject doubles as the lang/es.json key:

     php artisan email:update black-friday-2026 --audience=unsubscribed --subject="40% off Whisper Money until 1 December" --exclude-demo

     The code is the live Stripe promotion code BLACKFRIDAY40, which expires on
     1 December 2026 at 23:59 Europe/Madrid. --}}
<x-mail::message>
# {{ __('40% off your first year') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('From today until Tuesday 1 December at 23:59 (Madrid time), Whisper Money is 40% off for your whole first year.') }}

@include('mail.partials.black-friday-2026-offer')

{{ __('We would go for the yearly plan. It is the cheapest way in: €2.70 a month for bank connections that bring your movements in on their own, AI that categorises them for you, and rules, budgets and reports with no limits.') }}

<x-mail::button :url="route('subscribe')">
{{ __('Get 40% off') }}
</x-mail::button>

{{ __('We build Whisper Money just the two of us, with no investors and no ads. Your subscription is what pays for it, and your data stays yours.') }}

{{ __('Questions before then? Hit reply. We read every one.') }}

{{ __('Álvaro & Víctor') }}<br>
{{ __('Founders of Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
