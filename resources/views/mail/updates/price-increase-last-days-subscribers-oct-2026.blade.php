{{-- The second and last reminder for users with a live subscription on the low
     price. Follow-up to price-increase-subscribers-oct-2026, with a new
     identifier so everyone in the audience gets it. Sent by hand on
     26 September. Send it with exactly this subject, which doubles as the
     lang/es.json key, or Spanish readers get an English subject:

     php artisan email:update price-increase-last-days-subscribers-oct-2026 --audience=active-low-price --subject="The price changes on 1 October, yours does not" --exclude-demo

     Nothing is at stake for them, so there is no table and no CTA: this only
     exists so the new price on the site during launch week does not read as
     something that happened to them. --}}
<x-mail::message>
# {{ __('Four days, and nothing changes for you') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('On 1 October the price goes up to €8.99 a month, or €53.94 a year, for new subscriptions. You are about to see that number in a few places.') }}

**{{ __('Yours stays where it is, for as long as you keep your subscription.') }}**

{{ __('That is the whole email. Nothing to do, nothing to click.') }}

{{ __('Thank you for paying for this. It is what pays for the work.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
