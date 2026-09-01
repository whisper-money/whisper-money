{{-- The public page a shared card unfurls from. Everything on it is either the
     picture the owner chose to publish or a way back to the product. --}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('A month with Whisper Money') }}</title>

    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ __('A month with Whisper Money') }}">
    <meta property="og:description" content="{{ __('Percentages and streaks. No amounts.') }}">
    <meta property="og:image" content="{{ $imageUrl }}">
    <meta property="og:image:width" content="1080">
    <meta property="og:image:height" content="1350">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ $imageUrl }}">

    <style>
        :root { color-scheme: light; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 28px;
            padding: 40px 20px;
            background: #fafafa;
            color: #18181b;
            font-family: 'IBM Plex Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;
        }
        img { display: block; width: 100%; max-width: 420px; height: auto; border: 1px solid #e4e4e7; border-radius: 8px; }
        a { display: inline-block; padding: 12px 20px; border-radius: 6px; background: #18181b; color: #fff; font-size: 15px; font-weight: 600; text-decoration: none; }
        p { margin: 0; font-size: 13px; color: #71717a; }
    </style>
</head>
<body>
    <img src="{{ $imageUrl }}" alt="{{ __('A month with Whisper Money') }}">
    <a href="{{ url('/') }}">{{ __('Track your own money') }}</a>
    <p>whisper.money</p>
</body>
</html>
