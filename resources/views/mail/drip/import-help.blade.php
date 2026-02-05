<x-mail::message>
# {{ __('Need help importing your transactions, :name?', ['name' => $userName]) }}

{{ __("Hi! It's Victor, the founder of Whisper Money. I noticed you've completed your setup but haven't imported any transactions yet. Let me help you get started!") }}

## {{ __('How to Import Your Transactions') }}

**{{ __('Step 1: Export from your bank') }}**
{{ __('Log into your bank\'s website and look for "Export" or "Download transactions". Choose CSV format if available.') }}

**{{ __('Step 2: Upload to Whisper Money') }}**
{{ __('Go to your dashboard and click "Import Transactions". Select your CSV file and I\'ll map the columns automatically.') }}

**{{ __('Step 3: Review and confirm') }}**
{{ __('Check that everything looks correct and click "Import". Your transactions will be encrypted and stored securely.') }}

## {{ __('Prefer to Start Fresh?') }}

{{ __("You can also manually add transactions and account balances. Some users prefer to start tracking from today rather than importing history - that's totally fine! Do whatever works best for you.") }}

<x-mail::button :url="config('app.url') . '/dashboard'">
{{ __('Go to Dashboard') }}
</x-mail::button>

{{ __("If you're having trouble with the import or need help with your specific bank's format, just reply to this email. I personally handle support and I'm happy to help you figure it out.") }}

{{ __('Thanks for giving Whisper Money a try!') }}

Best,<br>
Víctor F,<br>
Founder of Whisper Money
</x-mail::message>
