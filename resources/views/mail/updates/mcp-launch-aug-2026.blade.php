{{-- Launch email for the AI connector. Send it with exactly this subject, which
     doubles as the lang/es.json key, or Spanish readers get an English subject:

     php artisan email:update mcp-launch-aug-2026 --subject="Ask ChatGPT how much you spent this month" --exclude-demo --}}
<x-mail::message>
# {{ __('I just wanted to know one number') }}

{{ __('Hi :name,', ['name' => $user->name]) }}

{{ __('A few Sundays ago I sat down to work out what I had spent eating out in July. One number. That was the whole plan.') }}

{{ __('I opened Whisper Money, went into the transactions, filtered by category, fixed the dates and added it all up. By the time I had the number I had forgotten why I wanted it.') }}

**{{ __('The data was never the problem. Getting an answer out of it was.') }}**

{{ __('So I went and fixed that.') }}

{{ __('You can now connect Whisper Money to ChatGPT and just ask:') }}

{{ __('"How much did I spend on restaurants this month?"') }}

{{ __('"How am I doing on my budgets?"') }}

{{ __('"Where is my money going?"') }}

{{ __('"Has my net worth grown this year?"') }}

{{ __('It answers with your real numbers, the ones from your own accounts.') }}

**{{ __('And it works the other way round as well.') }}**

{{ __('Say "note down 40 euros on groceries" and the transaction is waiting for you in the app. Same for setting up a new budget.') }}

{{ __('It works with Claude too, if that is the assistant you use.') }}

<x-mail::panel>
{{ __('One thing I want to be precise about, because this is a privacy first app. Nothing gets connected until you connect it yourself, from your settings, under AI Connector. While it is connected, the assistant reads your data in order to answer you, and those conversations live in your own account with that assistant, under their rules and not mine. When you want out, you remove Whisper Money from the connected apps inside ChatGPT and it stops answering. If you never connect it, nothing changes for you.') }}
</x-mail::panel>

{{ __('Connecting it is a handful of clicks, and it is a lot easier to watch than to read. So I recorded myself doing the whole thing, from connecting it to asking the first question.') }}

{{-- The video is Spanish already, so this heads-up is deliberately untranslated
     and only ships to the other locales. Do not add its key to lang/es.json. --}}
@unless (app()->isLocale('es'))
{{ __('The video is in Spanish. YouTube auto-generated subtitles do a decent job if you need them.') }}
@endunless

<x-mail::button :url="'https://youtu.be/QSSd6z5UZ_M'">
{{ __('Watch how it works') }}
</x-mail::button>

{{ __('It comes with Pro, by the way.') }}

{{ __('If you try it, hit reply and tell me what you asked it first. I read every email.') }}

Víctor Falcón Ruíz<br>
{{ __('Founder and solo developer, Whisper Money') }}
</x-mail::message>
