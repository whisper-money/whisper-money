{{-- The second and last reminder for users who cancelled but are still inside
     their paid period, on the low price. Follow-up to
     price-increase-cancelling-oct-2026, with a new identifier so everyone in
     the audience gets it. Sent by hand on Friday 25 September at 17:00
     Europe/Madrid. Send it with exactly this subject, which doubles as the
     lang/es.json key, or Spanish readers get an English subject:

     php artisan email:update price-increase-last-days-cancelling-oct-2026 --audience=cancelling-low-price --subject="Last chance to keep your old price" --exclude-demo

     The goal is that they reconsider and reactivate. No day count here, unlike
     the unsubscribed one. This audience has no constraint on how much period
     is left, so someone who cancelled an annual plan in August still has
     months: their deadline is the day their own period runs out, not
     30 September. What 1 October does change for them is that there stops
     being a cheaper subscription to come back to. --}}
<x-mail::message>
# {{ __('Reactivate it and you keep €3.99') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('You cancelled your subscription, but it keeps running until the end of your current period, and it is still on the old price.') }}

<x-mail::table>
| {{ __('Plan') }}        | {{ __('Today') }}  | {{ __('From 1 October') }} |
| :---------------------- | :----------------- | :------------------------- |
| **{{ __('Monthly') }}** | {{ __('€3.99') }}  | **{{ __('€8.99') }}**      |
| **{{ __('Yearly') }}**  | {{ __('€23.88') }} | **{{ __('€53.94') }}**     |
</x-mail::table>

{{ __('That price is tied to the subscription itself. While it is alive you can reactivate it and carry on at €3.99 a month, or €23.88 a year, for as long as you keep it. The day it ends, that price is gone for good, and I will not be able to give it back to you.') }}

{{ __('What changes on 1 October is that there stops being a cheaper subscription to come back to. Starting again after that would mean €8.99 a month, or €53.94 a year.') }}

{{ __('This is the last email I will send you about it. If you want to keep that price, reactivate before your subscription ends.') }}

<x-mail::button :url="route('settings.billing')">
{{ __('Reactivate my subscription') }}
</x-mail::button>

{{ __('The button takes you to Manage Plan, and from there Manage Subscription opens Stripe. It is a couple of clicks.') }}

{{ __('And if something made you cancel, reply to this email and tell me. If I can fix it, I would much rather do that than lose you.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
