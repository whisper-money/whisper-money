<x-mail::message>
# {{ __('Need help importing your transactions, :name?', ['name' => $userName]) }}

{{ __("Hi! It's Victor and Álvaro, the founders of Whisper Money. We noticed you've completed your setup but haven't imported any transactions yet. Let us help you get started!") }}

## {{ __('How to Import Your Transactions') }}

**{{ __('Step 1: Export from your bank') }}**
{{ __('Log into your bank\'s website and look for "Export" or "Download transactions". Choose CSV format if available.') }}

**{{ __('Step 2: Upload to Whisper Money') }}**
{{ __('Go to your dashboard and click "Import Transactions". Select your CSV file and we\'ll map the columns automatically.') }}

**{{ __('Step 3: Review and confirm') }}**
{{ __('Check that everything looks correct and click "Import". Your transactions are stored securely and never shared with anyone.') }}

## {{ __('Prefer to Connect Your Bank?') }}

{{ __("We also support Open Banking connections so your transactions sync automatically. Head to Settings > Connections to link your bank directly.") }}

## {{ __('Prefer to Start Fresh?') }}

{{ __("You can also manually add transactions and account balances. Some users prefer to start tracking from today rather than importing history - that's totally fine! Do whatever works best for you.") }}

<x-mail::button :url="config('app.url') . '/dashboard'">
{{ __('Go to Dashboard') }}
</x-mail::button>

{{ __("If you're having trouble with the import or need help with your specific bank's format, just reply to this email. We personally handle support and we're happy to help you figure it out.") }}

{{ __('Thanks for giving Whisper Money a try!') }}

{{ __('Best,') }}<br>
{{ __('Víctor & Álvaro') }}<br>
{{ __('Founders of Whisper Money') }}
</x-mail::message>
