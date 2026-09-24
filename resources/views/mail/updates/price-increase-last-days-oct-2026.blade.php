{{-- The second and last reminder before the price goes up, to the same audience
     as price-increase-oct-2026. A new identifier, so everyone in the audience
     gets it, including those who already read the first one. Sent by hand on
     Friday 25 September at 17:00 Europe/Madrid. Send it with exactly this
     subject, which doubles as the lang/es.json key, or Spanish readers get an
     English subject:

     php artisan email:update price-increase-last-days-oct-2026 --audience=unsubscribed --subject="Five days left at €3.99" --exclude-demo

     "Five days" counts Saturday to Wednesday 30 September at 23:59 CEST, plus
     what is left of Friday. Sent any later, the count in the subject is wrong.

     The audience is recomputed at send time, so anyone who subscribed after the
     first email drops out on their own. --}}
<x-mail::message>
# {{ __('The old price ends on Wednesday') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('The price of Whisper Money goes up on 1 October, and this is the last time I will write to you about it. You have until Wednesday 30 September to subscribe at the price you see today.') }}

<x-mail::table>
| {{ __('Plan') }}        | {{ __('Today') }}  | {{ __('From 1 October') }} |
| :---------------------- | :----------------- | :------------------------- |
| **{{ __('Monthly') }}** | {{ __('€3.99') }}  | **{{ __('€8.99') }}**      |
| **{{ __('Yearly') }}**  | {{ __('€23.88') }} | **{{ __('€53.94') }}**     |
</x-mail::table>

{{ __('Your price lives inside your subscription, not on the pricing page. Subscribe now and yours stays at €3.99 a month, or €23.88 a year, for as long as you keep it. The increase only applies to subscriptions created afterwards.') }}

{{ __('It is going up because bank connections and AI are what make Whisper Money worth opening, and both cost us more every month. There are two of us, Álvaro and me, and we would like to keep doing this for a long time.') }}

<x-mail::button :url="route('subscribe')">
{{ __('Keep the €3.99 price') }}
</x-mail::button>

{{ __('After 30 September at 23:59 CEST the old price is gone, and I will not be able to bring it back for you.') }}

{{ __('And if the free plan is what works for you, stay on it. It is not going anywhere, and I am glad to have you here either way.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
