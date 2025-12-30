<x-mail::message>
# We're sorry to see you go, {{ $userName }}

Hi! It's Victor, the founder of Whisper Money. I noticed you've cancelled your subscription, and I wanted to reach out personally.

First, thank you for giving Whisper Money a try. I hope it helped you get a better handle on your finances while keeping your data private.

## Before you go...

If there's anything that didn't work well for you, or if you have suggestions for improvement, I'd genuinely love to hear about it. As a solo founder, your feedback is invaluable in making Whisper Money better.

If you'd like to come back, here's a special offer just for you:

<x-mail::panel>
Use code **CONTINUE50** to get **50% off** all current and future payments - works for both monthly and yearly subscriptions.
</x-mail::panel>

<x-mail::button :url="config('app.url') . '/subscribe'">
Reactivate Your Subscription
</x-mail::button>

Your data and settings will be preserved, so you can pick up right where you left off.

If you have any questions or just want to chat, simply reply to this email. I read and respond to every message personally.

Thanks again for being part of this journey!

Best,<br>
Victor F,<br>
Founder of Whisper Money
</x-mail::message>
