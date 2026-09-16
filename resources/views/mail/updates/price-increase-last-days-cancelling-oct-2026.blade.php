{{-- The second and last reminder for users who cancelled but are still inside
     their paid period, on the low price. Follow-up to
     price-increase-cancelling-oct-2026, with a new identifier so everyone in
     the audience gets it. Sent by hand on 26 September. Send it with exactly
     this subject, which doubles as the lang/es.json key, or Spanish readers get
     an English subject:

     php artisan email:update price-increase-last-days-cancelling-oct-2026 --audience=cancelling-low-price --subject="After 1 October, €3.99 only exists inside your subscription" --exclude-demo

     No "four days" here, unlike the other two in this wave. This audience has
     no constraint on how much period is left, so someone who cancelled an
     annual plan in August still has months: their deadline is the day their own
     period runs out, not 30 September. What 1 October does change for them is
     that there stops being a cheaper subscription to come back to. --}}
<x-mail::message>
# {{ __('Your subscription is the last €3.99') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('Your subscription is cancelled but still running, and it is still on the old price.') }}

<x-mail::table>
| {{ __('Plan') }}        | {{ __('Today') }}  | {{ __('From 1 October') }} |
| :---------------------- | :----------------- | :------------------------- |
| **{{ __('Monthly') }}** | {{ __('€3.99') }}  | **{{ __('€8.99') }}**      |
| **{{ __('Yearly') }}**  | {{ __('€23.88') }} | **{{ __('€53.94') }}**     |
</x-mail::table>

{{ __('On 1 October €3.99 comes off the pricing page. After that it exists in one place only: subscriptions that never ended. Yours is one of those, until the day your period runs out.') }}

{{ __('Reactivate before that day and the price stays, for as long as you keep the subscription. Let the day pass and coming back costs €8.99 a month, or €53.94 a year.') }}

<x-mail::button :url="route('settings.billing')">
{{ __('Reactivate my subscription') }}
</x-mail::button>

{{ __('Manage Plan, then Manage Subscription, and Stripe does the rest.') }}

{{ __('And if you still want out, that is fine. Thank you for having paid for this, and I will not write to you about the price again.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
