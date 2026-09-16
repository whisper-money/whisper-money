{{-- For users who cancelled but are still inside their paid period and are on
     the low price. The --audience filter leaves out the A/B cohort on the high
     price, who have nothing to lose here. Send it with exactly this subject,
     which doubles as the lang/es.json key, or Spanish readers get an English
     subject:

     php artisan email:update price-increase-cancelling-oct-2026 --audience=cancelling-low-price --subject="If your subscription ends, you lose the €3.99 price" --exclude-demo --}}
<x-mail::message>
# {{ __('Before your subscription ends') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('You cancelled your Whisper Money subscription. It stays active until the end of the current period, and then it stops. That is your call, and I am not here to argue with it.') }}

{{ __('I am here because something changes on 1 October that I will not be able to undo for you later.') }}

{{ __('On 1 October the price goes up.') }}

<x-mail::table>
| {{ __('Plan') }}        | {{ __('Today') }}  | {{ __('From 1 October') }} |
| :---------------------- | :----------------- | :------------------------- |
| **{{ __('Monthly') }}** | {{ __('€3.99') }}  | **{{ __('€8.99') }}**      |
| **{{ __('Yearly') }}**  | {{ __('€23.88') }} | **{{ __('€53.94') }}**     |
</x-mail::table>

**{{ __('That is 125% more. More than double.') }}**

{{ __('Your subscription is on the old price. While it is alive, it stays there. Reactivate it before it ends and you carry on at €3.99, or €23.88 a year, for as long as you keep it.') }}

**{{ __('The day it ends, that price ends with it.') }}**

{{ __('Coming back later means the new price: €8.99 a month, or €53.94 a year. It applies to everyone who subscribes after 30 September at 23:59 CEST.') }}

{{ __('Why it is going up: our banking provider is expensive, and we want more of them so the app works outside Europe. AI does more of your work every month and it costs us money. And this has to pay for itself to last. We are two people, Álvaro and me, not a company with deep pockets.') }}

<x-mail::button :url="route('settings.billing')">
{{ __('Reactivate my subscription') }}
</x-mail::button>

{{ __('The button opens Manage Plan. From there, Manage Subscription takes you to Stripe. Two clicks and it is back.') }}

{{ __('Thank you for having paid for this. That, and the people on the free plan, is what has kept it going.') }}

{{ __('And if something here is what made you cancel, hit reply and tell me. That is worth more to me than the cancellation.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
