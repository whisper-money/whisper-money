{{-- Where a one-click unsubscribe lands when a browser followed the link. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Monthly summary turned off') }}</title>
    <style>
        :root { color-scheme: light; }
        body {
            margin: 0; min-height: 100vh; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 14px; padding: 40px 20px;
            background: #fafafa; color: #18181b; text-align: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
        }
        h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -0.02em; }
        p { margin: 0; font-size: 15px; line-height: 1.55; color: #52525b; max-width: 34rem; }
        a { color: #18181b; }
    </style>
</head>
<body>
    <h1>{{ __('Monthly summary turned off') }}</h1>
    <p>{{ __('You will not get the monthly report or its reminder again. Everything else stays as it was.') }}</p>
    <p><a href="{{ route('notifications.index') }}">{{ __('Change your email preferences') }}</a></p>
</body>
</html>
