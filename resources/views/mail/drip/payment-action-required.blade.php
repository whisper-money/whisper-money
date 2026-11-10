<x-mail::message>
@if ($isFinalNotice)
# {{ __('Last notice: your payment still needs confirming, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. This is our last reminder: the payment of :amount for your :plan still needs your confirmation. If it isn't confirmed in the coming days, your subscription will be canceled and bank sync and AI categorization will stop. Everything you've imported stays yours.", ['amount' => $amount, 'plan' => $planName]) }}
@elseif ($isReminder)
# {{ __('Your payment is still waiting for you, :name', ['name' => $userName]) }}

{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. The payment of :amount for your :plan still needs your confirmation. Until your bank gets it, the payment can't go through, and retrying on our side won't change that.", ['amount' => $amount, 'plan' => $planName]) }}
@else
# {{ __('Your bank needs you to confirm a payment, :name', ['name' => $userName]) }}

@if ($isFirstCharge)
{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. Your free trial has ended, and your bank wants you to confirm the first payment of :amount for your :plan before it goes through.", ['amount' => $amount, 'plan' => $planName]) }}
@else
{{ __("Hi! It's Víctor and Álvaro, the founders of Whisper Money. Your bank wants you to confirm the payment of :amount for your :plan before it goes through.", ['amount' => $amount, 'plan' => $planName]) }}
@endif

{{ __("It's a security check (3D Secure) that only you can complete, so we can't retry it for you. You keep full access to Whisper Money in the meantime.") }}
@endif

{{ __('It takes a minute: open the link and approve the payment in your banking app or with the code your bank sends you.') }}

<x-mail::button :url="$confirmPaymentUrl">
{{ __('Confirm payment') }}
</x-mail::button>

{{ __("If something isn't working for you, reply to this email and tell us. We read every reply ourselves.") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
