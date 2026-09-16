{{-- The second and last reminder before the price goes up, to the same audience
     as price-increase-oct-2026. A new identifier, so everyone in the audience
     gets it, including those who already read the first one. Sent by hand on
     26 September. Send it with exactly this subject, which doubles as the
     lang/es.json key, or Spanish readers get an English subject:

     php artisan email:update price-increase-last-days-oct-2026 --audience=unsubscribed --subject="Four days left at €3.99" --exclude-demo

     The audience is recomputed at send time, so anyone who subscribed after the
     first email drops out on their own. --}}
<x-mail::message>
# {{ __('Four days, then €3.99 is gone') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('I will keep this short, because the date is the whole of it. On 1 October the price of Whisper Money goes up, and you have four days left at €3.99.') }}

<x-mail::table>
| {{ __('Plan') }}        | {{ __('Today') }}  | {{ __('From 1 October') }} |
| :---------------------- | :----------------- | :------------------------- |
| **{{ __('Monthly') }}** | {{ __('€3.99') }}  | **{{ __('€8.99') }}**      |
| **{{ __('Yearly') }}**  | {{ __('€23.88') }} | **{{ __('€53.94') }}**     |
</x-mail::table>

{{ __('Subscribe before 30 September at 23:59 CEST and you keep €3.99 a month, or €23.88 a year, for as long as you keep the subscription. The price lives inside it, so a rise never touches yours.') }}

{{ __('The short version of why: bank connections and AI cost us more every month, and this has to pay for itself to last. We are still two people, Álvaro and me.') }}

<x-mail::button :url="route('subscribe')">
{{ __('Keep the €3.99 price') }}
</x-mail::button>

**{{ __('After that I cannot bring it back for you. €3.99 will not exist.') }}**

{{ __('And if the free plan is what suits you, stay on it. It is not going anywhere, and I am glad you are here either way.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
