<x-mail::message>
# {{ __("You're on the Whisper Money waiting list!") }}

{{ __("Hey there!") }}

{{ __("We're **Victor and Alvaro**, the founders of Whisper Money. Thanks so much for joining the list — it genuinely means a lot to us.") }}

{{ __('We built Whisper Money because we were tired of finance apps that mine your data and sell your habits to third parties. We wanted something simple, private, and actually useful.') }}

## {{ __("What is Whisper Money?") }}

{{ __("Whisper Money is a **privacy-first personal finance app** that puts you in control:") }}

- {{ __("**All your accounts in one place** — bank accounts, savings, investments, crypto, and more") }}
- {{ __("**Every transaction tracked** — import from CSV/XLS or connect via Open Banking") }}
- {{ __("**Smart budgets** — set goals, track progress, and stay on track every month") }}
- {{ __("**Cashflow at a glance** — see exactly where your money goes and how it evolves") }}
- {{ __("**Automation rules** — categorize transactions automatically, your way") }}
- {{ __("**Your data, always** — never shared with third parties, always under your control") }}

{{ __("It's personal finance, but actually private.") }}

## {{ __("Your Position in the Queue") }}

<x-mail::panel>
{{ __("You are currently **#:position** in line for early access.", ['position' => $position]) }}
</x-mail::panel>

## {{ __("Move Up — Share Your Personal Link") }}

{{ __("Every person who joins through your link moves you **10 positions forward** in the queue.") }}

<table class="action" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<div class="button button-primary" target="_blank" rel="noopener">{{ $referralUrl }}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>

{{ __("Share it with anyone who cares about their financial privacy — friends, family, your group chat. Each sign-up is 10 spots closer to the front.") }}

{{ __("We're working hard to open things up, and we can't wait to share Whisper Money with you.") }}

{{ __("Best,") }}<br>
{{ __('Álvaro & Víctor') }}<br>
{{ __("Founders of Whisper Money") }}
</x-mail::message>
