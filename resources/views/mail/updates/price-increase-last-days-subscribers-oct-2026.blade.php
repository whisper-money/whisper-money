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
# {{ __('Nothing changes for you') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('On 1 October Whisper Money goes to €8.99 a month, or €53.94 a year. You will probably see the new price around the site over the next few days, so here is what it means for you: nothing.') }}

**{{ __('Your subscription keeps the price you signed up at, for as long as you keep it.') }}** {{ __('The increase only applies to subscriptions created from 1 October onwards.') }}

{{ __('There is nothing for you to do here, and nothing to click. I just did not want to leave you wondering.') }}

{{ __('Thank you for supporting us. It helps us keep Whisper Money going and carry on making it better.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
