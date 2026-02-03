<x-mail::message>
# Verify your email, {{ $userName }}!

Hi! I'm Victor, the founder of Whisper Money. Thanks for signing up — I just need you to verify your email address to get started.

Once verified, you'll be able to set up your encryption key and start tracking your finances with full privacy.

<x-mail::button :url="$verificationUrl">
Verify Email Address
</x-mail::button>

If you didn't create a Whisper Money account, you can safely ignore this email.

Best,<br>
Víctor F,<br>
Founder of Whisper Money

<x-mail::subcopy>
If you're having trouble clicking the "Verify Email Address" button, copy and paste the URL below into your web browser: <span class="break-all">[{{ $verificationUrl }}]({{ $verificationUrl }})</span>
</x-mail::subcopy>
</x-mail::message>
