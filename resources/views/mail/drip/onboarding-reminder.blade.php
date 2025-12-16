<x-mail::message>
# Hey {{ $userName }}, is everything okay?

I noticed you signed up for Whisper Money but haven't completed your setup yet. I wanted to check in and see if something went wrong or if you need any help.

## Common Questions

**Is the encryption confusing?**
No worries! Your encryption key is automatically generated and stored securely on your device. You don't need to remember anything.

**Not sure how to import transactions?**
We support CSV imports from most banks. Just export your transactions and upload them - it takes less than a minute.

**Something not working?**
Just reply to this email and let me know what's happening. I personally read every response and will help you get started.

<x-mail::button :url="config('app.url') . '/onboarding'">
Continue Setup
</x-mail::button>

Looking forward to hearing from you!

Best,<br>
{{ config('app.name') }}
</x-mail::message>
