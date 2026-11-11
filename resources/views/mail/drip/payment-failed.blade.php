<x-mail::message>
@if ($isFinalNotice)
# {{ __('Last notice about your payment, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. This is our last reminder: we still couldn't charge :amount for your :plan. We'll make the last attempts in the coming days, and if those fail too, your subscription will be canceled and bank sync and AI categorization will stop. Everything you've imported stays yours.", ['amount' => $amount, 'plan' => $planName]) }}
@elseif ($isReminder)
# {{ __('Your payment is still pending, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. We still haven't been able to charge :amount for your :plan. We'll keep trying for a while longer, and you keep full access in the meantime.", ['amount' => $amount, 'plan' => $planName]) }}
@else
# {{ $isFirstChargeAfterTrial ? __('Your first payment didn\'t go through, :name', ['name' => $userName]) : __('Your payment didn\'t go through, :name', ['name' => $userName]) }}

@if ($isFirstChargeAfterTrial)
{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. Your free trial has ended, and when we tried to charge the first payment of :amount for your :plan, your bank declined the card.", ['amount' => $amount, 'plan' => $planName]) }}
@else
{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. We tried to charge :amount for your :plan, and your bank declined the card.", ['amount' => $amount, 'plan' => $planName]) }}
@endif

{{ __('It happens, usually because of a temporary limit or not enough funds on the day. We will try again automatically over the coming days, and you keep full access to Whisper Money in the meantime.') }}
@endif

{{ __("Card changed or about to? Update your payment method and we'll use it for the next attempt.") }}

<x-mail::button :url="$updatePaymentUrl">
{{ __('Update payment method') }}
</x-mail::button>

@unless ($isFinalNotice)
{{ __('Already sorted it out with your bank? Then there is nothing to do: the next retry will go through.') }}

@endunless
{{ __("If something isn't working for you, reply to this email and tell us. We read every reply ourselves.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
