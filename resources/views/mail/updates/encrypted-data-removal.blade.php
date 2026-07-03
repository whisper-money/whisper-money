<x-mail::message>
# We've missed you at Whisper Money

Hi {{ $user->name }},

We noticed you haven't signed in to Whisper Money for a while. To keep things tidy, **accounts that stay inactive will be removed** — and yours is on that list.

If you'd like to keep your account and your data, just **sign in within the next 7 days**:

<x-mail::button :url="route('login')">
Sign in to Whisper Money
</x-mail::button>

## A lot has changed since you were last here

We've been busy adding features to make managing your money effortless:

- **AI-powered insights** that categorise your transactions automatically.
- **Bank integrations** that import your transactions for you — no more manual entry.
- And plenty of smaller improvements across the app.

It's a great time to come back and take a look.

If you don't sign in before then, your account and all of its data will be permanently deleted.

If you have any questions, **just reply to this email** — we read every message personally.

Thanks,<br>
The Whisper Money team
</x-mail::message>
