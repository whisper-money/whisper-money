{{-- One email to every audience that can still get the old price, the three the
     price-increase-last-days-* emails went to: free users, subscribers on the
     low price, and low-price subscribers who cancelled but are still inside
     their period. On 29 and 30 September a bug had the app already showing the
     new €8.99 price, so the old price gets 24 more hours: the deadline moves
     from 30 September to Thursday 1 October at 23:59 CEST. Sent by hand on
     Wednesday 30 September, which is what makes "tomorrow" true.

     Send it with exactly this subject, which doubles as the lang/es.json key, or
     Spanish readers get an English subject. One command per audience, all with
     the same view and so the same identifier, which dedupes anyone who lands in
     two of them:

     php artisan email:update price-increase-extension-oct-2026 --audience=unsubscribed --subject="My mistake: €3.99 lasts until Thursday" --exclude-demo
     php artisan email:update price-increase-extension-oct-2026 --audience=cancelling-low-price --subject="My mistake: €3.99 lasts until Thursday" --exclude-demo
     php artisan email:update price-increase-extension-oct-2026 --audience=active-low-price --subject="My mistake: €3.99 lasts until Thursday" --exclude-demo

     The body is the same for everyone. The closing block is the only thing that
     changes, read off the same subscription state the audiences are built from:
     a cancelled subscription still running (onGracePeriod) is sent to reactivate
     it from the billing page, since /subscribe bounces them to the dashboard; a
     subscription Stripe still collects on has nothing to do; everyone else is
     sent to subscribe. The subscribe button only charges €3.99 while production
     runs on the low price tier. --}}
<x-mail::message>
# {{ __('One more day at the old price') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('Yesterday and today, Whisper Money showed the wrong price.') }}

{{ __('It said €8.99 a month, while the old €3.99 was still valid until tonight.') }}

{{ __('That was a bug, and it was mine. I am sorry.') }}

{{ __('Those were the last two days at the old price, and the app was telling everyone they were already over.') }}

**{{ __('So I am giving you one more day: the old price now lasts until tomorrow, Thursday 1 October, at 23:59 CEST.') }}**

@if ($user->subscription('default')?->onGracePeriod())
{{ __('You cancelled, but your subscription is still running on the old price. Reactivate it before it ends and you keep that price for as long as you keep the subscription.') }}

<x-mail::button :url="route('settings.billing')">
{{ __('Reactivate my subscription') }}
</x-mail::button>
@elseif ($user->collectableSubscription())
**{{ __('None of this changes what you pay.') }}** {{ __('Your subscription keeps the price you signed up at for as long as you keep it, so there is nothing for you to do.') }}
@else
{{ __('Subscribe before then and you keep €3.99 a month, or €23.88 a year, for as long as you keep your subscription.') }}

<x-mail::button :url="route('subscribe')">
{{ __('Subscribe at €3.99') }}
</x-mail::button>
@endif

{{ __('If the bug caused you any trouble, reply to this email and I will sort it out.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
