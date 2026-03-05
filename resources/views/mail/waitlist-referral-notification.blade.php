<x-mail::message>
# {{ __("Someone just joined with your link!") }}

{{ __("Hey! We have some great news.") }}

{{ __("Someone just signed up to the Whisper Money waiting list using **your referral link**. We've moved you **10 positions forward** in the queue as a thank you.") }}

<x-mail::panel>
{{ __("You are now **#:position** in line for early access.", ['position' => $newPosition]) }}
</x-mail::panel>

{{ __("Every person who joins through your link moves you another 10 spots closer to the front — so keep sharing!") }}

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

{{ __("Thanks for spreading the word. It means everything to us.") }}

{{ __("Best,") }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __("Founders of Whisper Money") }}
</x-mail::message>
