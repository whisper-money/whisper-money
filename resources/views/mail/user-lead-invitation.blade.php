<x-mail::message>
# {{ __('Your early access to Whisper Money is ready') }}

{{ __('Hey there!') }}

{{ __("We're Victor and Álvaro, the founders of Whisper Money. You signed up a while back to hear about our privacy-first personal finance app, and we're excited to tell you - we're live!") }}

## {{ __('What is Whisper Money?') }}

{{ __('We built Whisper Money because we were tired of giving our financial data to big companies who use it for who knows what. Whisper Money **never shares your data with third parties** - you are always the owner of your financial information.') }}

{{ __("It's personal finance, but actually private.") }}

{{ __('This is your exclusive invitation to get full access to everything:') }}

- {{ __('Unlimited transaction imports') }}
- {{ __('Automated categorization rules') }}
- {{ __('Multiple account tracking') }}
- {{ __('Your data stays yours—never shared with third parties') }}
- {{ __('Mobile app (iOS & Android)') }}

<x-mail::button :url="config('app.url') . '/register'">
{{ __('Get Started') }}
</x-mail::button>

## {{ __('Built by two people, for real people') }}

{{ __("We're two founders building this because we believe you deserve a finance app that actually respects your privacy. Every subscription helps us keep the lights on and build features you actually want. No investors, no board meetings, just us trying to build something useful and private.") }}

{{ __('**Have feedback? Questions? Issues?** Just hit reply to this email. We read and respond to every message personally.') }}

{{ __("Thanks for being interested in what we're building!") }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
