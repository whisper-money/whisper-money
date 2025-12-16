<x-mail::message>
# Welcome to Whisper Money, {{ $userName }}!

Thank you for joining Whisper Money, the privacy-first personal finance app that puts you in control.

## Your Data is Truly Private

Unlike other finance apps, Whisper Money uses **end-to-end encryption**. This means:

- Your financial data is encrypted with a key that only you control
- We cannot read your transactions, balances, or any personal information
- Your encryption key never leaves your device

## No AI, No Data Mining

We don't use AI to analyze your spending patterns or sell insights about your habits. Your financial data stays between you and your spreadsheet-loving self.

## Getting Started is Easy

Here's what you can do with Whisper Money:

- **Import transactions** from your bank in seconds
- **Track account balances** across all your accounts
- **Create automated rules** to categorize transactions automatically
- **Stay organized** without compromising your privacy

<x-mail::button :url="config('app.url') . '/onboarding'">
Complete Your Setup
</x-mail::button>

If you have any questions, just reply to this email. I'm here to help!

Best,<br>
{{ config('app.name') }}
</x-mail::message>
