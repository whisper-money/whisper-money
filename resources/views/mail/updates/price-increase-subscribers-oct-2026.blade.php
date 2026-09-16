{{-- For users with a live subscription on the low price: the increase does not
     touch them, and this says so before they read about it elsewhere. The
     --audience filter leaves out the A/B cohort on the high price, for whom
     none of this is true. Send it with exactly this subject, which doubles as
     the lang/es.json key, or Spanish readers get an English subject:

     php artisan email:update price-increase-subscribers-oct-2026 --audience=active-low-price --subject="Your price is not going up" --exclude-demo --}}
<x-mail::message>
# {{ __('Your price is not going up') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('On 1 October the price of Whisper Money goes up, for new subscriptions.') }}

<x-mail::table>
| {{ __('Plan') }}        | {{ __('Today') }}  | {{ __('From 1 October') }} |
| :---------------------- | :----------------- | :------------------------- |
| **{{ __('Monthly') }}** | {{ __('€3.99') }}  | **{{ __('€8.99') }}**      |
| **{{ __('Yearly') }}**  | {{ __('€23.88') }} | **{{ __('€53.94') }}**     |
</x-mail::table>

**{{ __('Yours is not one of them. You keep paying what you pay now.') }}**

{{ __('Your price lives inside your own subscription. Raising it only changes what new ones cost. For as long as you keep yours, it does not move.') }}

{{ __('There is one way to lose it: cancel. If your subscription ends and you come back later, you come back at the new price.') }}

{{ __('Why it is going up: our banking provider is expensive, and we want more of them so the app works outside Europe. AI does more of your work every month and it costs us money. And this has to pay for itself to last. We are two people, Álvaro and me, not a company with deep pockets.') }}

{{ __('There is nothing for you to do. I just did not want you seeing the new price on the site and wondering what it meant for you.') }}

{{ __('Thank you for paying for this. That, and the people on the free plan, is what keeps it going.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>
<a href="{{ $unsubscribeUrl }}">{{ __('Stop receiving news and offers') }}</a>
</x-slot:subcopy>
</x-mail::message>
