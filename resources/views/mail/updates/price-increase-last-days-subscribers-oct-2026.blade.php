{{-- The second and last reminder for users with a live subscription on the low
     price. Follow-up to price-increase-subscribers-oct-2026, with a new
     identifier so everyone in the audience gets it. Sent by hand on Friday
     25 September at 17:00 Europe/Madrid. Send it with exactly this subject,
     which doubles as the lang/es.json key, or Spanish readers get an English
     subject:

     php artisan email:update price-increase-last-days-subscribers-oct-2026 --audience=active-low-price --subject="After 1 October, nobody else can get your price" --exclude-demo

     The goal is that they stay subscribed: their price will never be offered
     again, and cancelling loses it for good. No table and no CTA, since they
     already know what they pay and the only thing to ask of them is to stay. --}}
<x-mail::message>
# {{ __('Keep your subscription, keep your price') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('On 1 October Whisper Money goes to €8.99 a month, or €53.94 a year. From that day on, nobody who subscribes can get the price you pay now.') }}

**{{ __('Your subscription keeps the price you signed up at, for as long as you keep it.') }}**

{{ __('But if you cancel and your subscription ends, that price is gone for good. Coming back later would mean paying the new one, and I will not be able to give you the old one back.') }}

{{ __('So if you ever think about cancelling, reply to this email first. If something is not working for you, I would rather fix it.') }}

{{ __('Thank you for supporting us. Subscriptions like yours are what keep Whisper Money going.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
