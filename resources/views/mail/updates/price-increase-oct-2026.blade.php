{{-- Last call before the price goes up, for users with no subscription and no
     trial. Send it with exactly this subject, which doubles as the lang/es.json
     key, or Spanish readers get an English subject:

     php artisan email:update price-increase-oct-2026 --audience=unsubscribed --subject="€3.99 becomes €8.99 on 1 October" --exclude-demo

     The whole audience goes out in one batch, which is the default: SES allows
     50,000 a day and the `emails` limiter 10 a second, so it drains in under
     ten minutes. Nobody on the high price can match this audience, and anyone
     still inside a cancelled subscription is left to
     price-increase-cancelling-oct-2026, since /subscribe would only bounce
     them to the dashboard. --}}
<x-mail::message>
# {{ __('I am raising the price on 1 October') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('On 1 October the price of Whisper Money goes up. I would rather tell you myself than have you find it on the pricing page.') }}

<x-mail::table>
| {{ __('Plan') }}        | {{ __('Today') }}  | {{ __('From 1 October') }} |
| :---------------------- | :----------------- | :------------------------- |
| **{{ __('Monthly') }}** | {{ __('€3.99') }}  | **{{ __('€8.99') }}**      |
| **{{ __('Yearly') }}**  | {{ __('€23.88') }} | **{{ __('€53.94') }}**     |
</x-mail::table>

**{{ __('That is 125% more. More than double.') }}**

{{ __('Subscribe before 30 September at 23:59 CEST and you keep the price you see today. Not for a year. For as long as you keep the subscription.') }}

{{ __('Your price lives inside your own subscription. Raising it only changes what new ones cost. Yours does not move.') }}

{{ __('On the yearly plan that is €30.06, every year you stay.') }}

{{ __('There are three reasons.') }}

{{ __('Bank connections are what make this app worth opening, and our provider charges us for every one of them. We want more providers, so it works outside Europe too.') }}

{{ __('AI does more of your work every month. Categories, rules, answers about your own numbers. All of it costs us money.') }}

{{ __('And this has to pay for itself to last. We are putting in more hours than I can count, and €3.99 does not cover them.') }}

{{ __('We are two people. Álvaro and me, on this every day. No company with deep pockets behind us, and no wish to become one.') }}

<x-mail::button :url="route('subscribe')">
{{ __('Subscribe before 1 October') }}
</x-mail::button>

{{ __('From 1 October the page says €8.99. There is no going back to €3.99.') }}

{{ __('Thank you for being here, whether you pay for a plan or use the free one. Both keep this going.') }}

{{ __('Questions before then? Hit reply. I read every one.') }}

Víctor Falcón Ruíz<br>
{{ __('Co-founder and solo developer, Whisper Money') }}

<x-slot:subcopy>@include('mail.partials.marketing-unsubscribe')</x-slot:subcopy>
</x-mail::message>
