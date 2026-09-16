{{-- The second and last reminder for users who cancelled but are still inside
     their paid period, on the low price. Follow-up to
     price-increase-cancelling-oct-2026, with a new identifier so everyone in
     the audience gets it. Sent by hand on 26 September. Send it with exactly
     this subject, which doubles as the lang/es.json key, or Spanish readers get
     an English subject:

     php artisan email:update price-increase-last-days-cancelling-oct-2026 --audience=cancelling-low-price --subject="Your subscription still has the old price" --exclude-demo

     No "four days" here, unlike the other two in this wave. This audience has
     no constraint on how much period is left, so someone who cancelled an
     annual plan in August still has months: their deadline is the day their own
     period runs out, not 30 September. What 1 October does change for them is
     that there stops being a cheaper subscription to come back to. --}}
<x-mail::message>
# {{ __('Reactivate it and you keep €3.99') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('You cancelled your subscription, but it is still running until the end of your current period, and it is still on the old price.') }}

<x-mail::table>
| {{ __('Plan') }}        | {{ __('Today') }}  | {{ __('From 1 October') }} |
| :---------------------- | :----------------- | :------------------------- |
| **{{ __('Monthly') }}** | {{ __('€3.99') }}  | **{{ __('€8.99') }}**      |
| **{{ __('Yearly') }}**  | {{ __('€23.88') }} | **{{ __('€53.94') }}**     |
</x-mail::table>

{{ __('That price is tied to the subscription itself. While it is alive you can reactivate it and carry on at €3.99 a month, or €23.88 a year, for as long as you keep it. The day it ends, the price goes with it.') }}

{{ __('What changes on 1 October is that there stops being a cheaper subscription to come back to. Starting again after that would mean €8.99 a month, or €53.94 a year.') }}

<x-mail::button :url="route('settings.billing')">
{{ __('Reactivate my subscription') }}
</x-mail::button>

{{ __('The button takes you to Manage Plan, and from there Manage Subscription opens Stripe. It is a couple of clicks.') }}

{{ __('And if you would rather leave it, that is completely fine. Thank you for supporting Whisper Money for as long as you did. It is what has got us this far.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
