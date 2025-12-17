<x-mail::message>
# Hey {{ $userName }}, is everything okay?

Hi! It's Victor, the founder of Whisper Money. I noticed you signed up but haven't completed your setup yet. I wanted to check in personally and see if something went wrong or if you need any help.

I know setting up a new app can be overwhelming, and I want to make sure you have everything you need to get started.

## Common Questions

**Is the encryption confusing?**
No worries! Your encryption key is automatically generated and stored securely on your device. You don't need to remember anything - I've made it as simple as possible.

**Not sure how to import transactions?**
I support CSV imports from most banks. Just export your transactions and upload them - it takes less than a minute. If your bank format is different, just let me know and I can help.

**Something not working?**
Just reply to this email and let me know what's happening. I personally read every response and will help you get started. This is my project, so I care about making sure it works for you.

<x-mail::button :url="config('app.url') . '/onboarding'">
Continue Setup
</x-mail::button>

Looking forward to hearing from you! And if you decide Whisper Money isn't for you, that's totally fine - but I'd love to know why so I can improve.

Best,<br>
Víctor F,<br>
Founder of Whisper Money
</x-mail::message>
