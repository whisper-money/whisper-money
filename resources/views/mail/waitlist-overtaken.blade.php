<x-mail::message>
# {{ __("Someone just overtook you in the queue!") }}

{{ __("Hey! A quick heads-up.") }}

{{ __("Someone on the Whisper Money waiting list just invited a friend, which moved them ahead of you in the queue.") }}

<x-mail::panel>
{{ __("You are now **#:position** in line for early access.", ['position' => $newPosition]) }}
</x-mail::panel>

{{ __("The good news? You can do exactly the same thing — share your personal link and jump **10 positions forward** for every person who joins through it.") }}

<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<div class="button button-primary" target="_blank" rel="noopener">{{ $referralUrl }}</div>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>

{{ __("Share it with friends, family, or anyone who cares about their financial privacy. Every sign-up moves you 10 spots closer to the front.") }}

{{ __("Best,") }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __("Founders of Whisper Money") }}
</x-mail::message>
