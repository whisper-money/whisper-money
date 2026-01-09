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

# Skip confirmation prompt (for scripts/automation)
php artisan email:update jan-2026-updates --force
```

### 3.1. Rate Limiting

**Important**: The command automatically rate limits to **50 emails per day** to avoid overwhelming your email service and to maintain good sender reputation.

For example:
- **50 users**: All sent immediately
- **125 users**: 50 sent today, 50 tomorrow, 25 the day after
- **250 users**: 50 per day over 5 days

The command will show you the schedule:
```
Found 126 user(s).
Rate limit: 50 emails per day
Successfully queued 126 update email(s) to the 'emails' queue!
Emails will be sent over 2 day(s) (50 emails per day)
```

Jobs are queued with delays automatically - you don't need to do anything special!

### 4. Command Arguments

- `view`: The name of your template file (without .blade.php extension)
- `identifier`: A unique tracking identifier to prevent duplicate sends

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
