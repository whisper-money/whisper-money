# Update Email Templates

This directory contains markdown templates for sending update emails to all users.

## How to Use

### 1. Create Your Email Template

Create a new Blade file in this directory with your update message:

```blade
<!-- resources/views/mail/updates/jan-2026-updates.blade.php -->
<x-mail::message>
# What's New in January 2026

Hi {{ $user->name }},

We've shipped some exciting updates this month:

- **Feature A**: Description of new feature
- **Feature B**: Description of improvement
- **Bug Fix C**: Description of fix

<x-mail::button :url="config('app.url')">
Check it out
</x-mail::button>

Thanks for using Whisper Money!

Victor, Founder of Whisper Money
</x-mail::message>
```

### 1.1. Write it bilingual

Users get emails in their own locale (`User` implements `HasLocalePreference`),
so wrap every line in `__()` and put the Spanish in `lang/es.json`. The pattern
to copy is `resources/views/mail/bank-outage.blade.php`, or the most recent
update email in this directory.

The **subject is a translation key too**: `UpdateEmail::envelope()` passes
`--subject` through `__()`. So the English subject you type on production must
match a key in `lang/es.json` character for character, or Spanish readers get a
Spanish body under an English subject. An untranslated subject is not an error,
it just goes out verbatim.

Two things worth knowing:

- `tests/Feature/LocalizationTest.php` only scans `resources/js`, so it will
  **not** catch a missing Blade or subject translation. Cover the template with
  a Pest test that renders it in `en` and `es`.
- Keep the exact send command in a Blade comment at the top of the template, so
  the subject string lives next to the copy it belongs to.

### 2. Available Variables

All templates have access to:
- `$user` - The User model instance with all properties (name, email, etc.)

### 3. Send the Email

Once you've created your template and deployed it to production:

```bash
# Basic usage
php artisan email:update jan-2026-updates

# With custom identifier
php artisan email:update jan-2026-updates jan-2026-updates

# With custom subject
php artisan email:update jan-2026-updates --subject="Exciting January Updates!"

# Exclude demo account
php artisan email:update jan-2026-updates --exclude-demo

# Only users with no subscription and no trial
php artisan email:update jan-2026-updates --audience=unsubscribed

# Only users who cancelled but are still inside their period, on the old price
php artisan email:update jan-2026-updates --audience=cancelling-low-price

# Drip it out 500 a day instead of sending the whole audience at once
php artisan email:update jan-2026-updates --per-day=500

# Skip confirmation prompt (for scripts/automation)
php artisan email:update jan-2026-updates --force
```

### 3.1. Rate Limiting

By default the whole audience is queued at once. The ceiling that matters is
SES: 50,000 sends a day on this account, which `--per-day` defaults to, and 10 a
second, which the `emails` queue limiter (`AppServiceProvider`) is set to.
4,600 emails drain in under ten minutes.

Pass `--per-day` to go slower than that, and the command spreads the send over
days, that many at a time. With `--per-day=50` and 126 users: 50 today, 50
tomorrow, 26 the day after. Jobs are queued with the delay already on them.

```
Found 126 user(s).
Rate limit: 50 emails per day
Successfully queued 126 update email(s) to the 'emails' queue!
Emails will be sent over 3 day(s) (50 emails per day)
```

### 3.2. Audience

`--audience` decides who is in the send. It defaults to `all`.

| Value | Who |
| --- | --- |
| `all` | Every user (deleted ones are skipped by the job). |
| `unsubscribed` | Nothing Stripe can still collect on, no trial running, and no subscription still inside its period. The last one matters: `/subscribe` redirects anyone with a valid subscription (grace period included) to the dashboard, so they would get an email with a dead CTA. |
| `cancelling-low-price` | Cancelled but still inside their period, on a price that is not the high tier's. The high-tier price IDs are resolved from their lookup keys through Stripe, and the command refuses to send if it cannot resolve them. |

`unsubscribed` refuses to run while `subscriptions.enabled` is false, because
then every user reads as unsubscribed.

### 4. Command Arguments

- `view`: The name of your template file (without .blade.php extension)
- `identifier`: A unique tracking identifier to prevent duplicate sends
- `--audience`: Who to send to (see 3.2). Defaults to `all`
- `--per-day`: How many emails to queue per day. Defaults to 50,000, the SES daily quota

### 5. How Tracking Works

Each update email is tracked using:
- Email type: "Update" (stored in DripEmailType enum)
- Email identifier: Your custom identifier (e.g., "jan-2026-updates")

This means:
- ✅ Running the same command twice won't send duplicates
- ✅ Users who already received this update will be skipped
- ✅ You can send different update emails (with different identifiers) to the same users

### 6. Email Components

Use Laravel's built-in mail components:

```blade
<!-- Button -->
<x-mail::button :url="$url">
Click Here
</x-mail::button>

<!-- Panel -->
<x-mail::panel>
Important information here
</x-mail::panel>

<!-- Table -->
<x-mail::table>
| Header 1 | Header 2 |
|----------|----------|
| Cell 1   | Cell 2   |
</x-mail::table>
```

### 7. Example Workflow

```bash
# 1. Create your template locally
vim resources/views/mail/updates/feb-2026-updates.blade.php

# 2. Test locally (optional - create test user first)
php artisan email:update feb-2026-updates test-feb-2026

# 3. Commit and push
git add resources/views/mail/updates/feb-2026-updates.blade.php
git commit -m "Add February 2026 update email"
git push

# 4. Deploy to production
# ... your deployment process ...

# 5. Send on production
php artisan email:update feb-2026-updates feb-2026-updates
```

## Best Practices

1. **Use descriptive identifiers**: `jan-2026-product-updates` is better than `update1`
2. **Test locally first**: Send to a test user before production
3. **Version control everything**: All templates should be committed to git
4. **Keep it concise**: Users appreciate brief, scannable updates
5. **Include CTAs**: Use buttons to drive users back to the app
6. **Consistent voice**: Maintain the personal, privacy-focused tone

## Learn More

- [Laravel Markdown Mailable Docs](https://laravel.com/docs/12.x/mail#markdown-mailables)
- [Laravel Mail Components](https://laravel.com/docs/12.x/mail#markdown-components)
