{{--
    The monthly summary. A standalone view rather than a Markdown mail, because
    the design is a report — a headline, an analysis, a shareable card, seven
    sentences with charts beside them and a short list of things to do — and none
    of that survives Markdown.

    Layout is tables with inline styles throughout. The colours, the 570px card
    and the 32px content cell come from the mail theme the rest of the emails
    already use, so this reads as part of the same family.

    The order is deliberate and was argued: headline, then the analysis, then the
    card, and only then the figures. The analysis is what a reader upgrades for,
    so it goes where it will be seen; the headline above it already carries the
    month's number, so nothing is buried by the swap.
--}}
@php
    $ink = '#18181b'; $body = '#52525b'; $muted = '#a1a1aa'; $rule = '#e4e4e7'; $wash = '#fafafa';
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <title>{{ $subject }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8"/>
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <style>
        @media only screen and (max-width: 600px) {
            .shell { width: 100% !important; }
            .stack, .stack > tbody, .stack > tbody > tr, .stack > tbody > tr > td { display: block !important; width: 100% !important; }
            .stack-gap { padding-top: 16px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:{{ $wash }};-webkit-text-size-adjust:none;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:{{ $wash }};">
<tr><td align="center" style="padding:24px 15px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

    {{-- Preheader: the line the inbox shows before anything is opened, and the
         cheapest place for the card to exist. --}}
    <div style="display:none;font-size:1px;color:{{ $wash }};line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{{ $preheader }}</div>

    <table role="presentation" class="shell" cellpadding="0" cellspacing="0" border="0" width="570" style="width:570px;background:#ffffff;border:1px solid {{ $rule }};border-radius:4px;">

        <tr><td style="padding:16px 32px;border-bottom:1px solid {{ $rule }};">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
                <td align="left" style="font-size:13px;font-weight:600;color:{{ $ink }};letter-spacing:-0.02em;">
                    <img src="{{ $appUrl }}/images/whisper_money_e.png" width="18" height="18" alt="" style="vertical-align:-4px;margin-right:6px;">whisper.money
                </td>
                <td align="right" style="font-size:10px;font-weight:600;letter-spacing:0.09em;text-transform:uppercase;color:{{ $muted }};">{{ __(':month summary', ['month' => $monthName]) }}</td>
            </tr></table>
            @if ($spaceName !== null)
                {{-- Only shown to people who can see more than one space, so a
                     single-space reader never wonders what a space is. --}}
                <p style="margin:10px 0 0;font-size:11px;color:{{ $muted }};">{{ __('Space: :name', ['name' => $spaceName]) }}</p>
            @endif
        </td></tr>

        <tr><td style="padding:32px;">

            <h1 style="margin:0;font-size:25px;line-height:1.18;font-weight:700;letter-spacing:-0.02em;color:{{ $ink }};">{{ $headline }}</h1>
            <p style="margin:12px 0 0;font-size:15px;line-height:1.55;color:{{ $body }};">{{ $lede }}</p>

            @if (! $complete)
                <p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:{{ $muted }};">{{ $incompleteNotice }}</p>
            @endif

            @include('mail.summary.analysis')

            @include('mail.summary.share')

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:34px 0 0;"><tr>
                <td align="left" style="font-size:15px;font-weight:700;color:{{ $ink }};letter-spacing:-0.01em;">{{ __('The rest of :month', ['month' => $monthName]) }}</td>
                <td align="right" style="font-size:10px;font-weight:600;letter-spacing:0.09em;text-transform:uppercase;color:{{ $muted }};">{{ trans_choice(':count figure|:count figures', count($rows), ['count' => count($rows)]) }}</td>
            </tr></table>

            <div style="margin-top:8px;">
                @foreach ($rows as $row)
                    @include('mail.summary.row', ['row' => $row])
                @endforeach
            </div>

            @if ($achievements !== null)
                @include('mail.summary.achievements')
            @endif

            @if (count($todos) > 0)
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:34px 0 14px;"><tr>
                    <td align="left" style="font-size:15px;font-weight:700;color:{{ $ink }};letter-spacing:-0.01em;">{{ trans_choice('One thing to close :month|:count things to close :month', count($todos), ['count' => count($todos), 'month' => $monthName]) }}</td>
                    <td align="right" style="font-size:10px;font-weight:600;letter-spacing:0.09em;text-transform:uppercase;color:{{ $muted }};">{{ __('5 minutes') }}</td>
                </tr></table>

                @foreach ($todos as $index => $todo)
                    @include('mail.summary.todo', ['todo' => $todo, 'number' => $index + 1])
                @endforeach
            @endif

        </td></tr>
    </table>

    <table role="presentation" class="shell" cellpadding="0" cellspacing="0" border="0" width="570" style="width:570px;">
        <tr><td align="center" style="padding:22px 0 0;">
            <p style="margin:0 0 7px;font-size:11px;line-height:1.6;color:{{ $muted }};">{{ __('You get this email because your monthly summary is on.') }}</p>
            <p style="margin:0 0 7px;font-size:11px;line-height:1.6;color:{{ $muted }};">
                <a href="{{ $preferencesUrl }}" style="color:{{ $muted }};text-decoration:underline;">{{ __('Email preferences') }}</a> ·
                <a href="{{ $unsubscribeUrl }}" style="color:{{ $muted }};text-decoration:underline;">{{ __('Turn the summary off') }}</a> ·
                <a href="{{ $historyUrl }}" style="color:{{ $muted }};text-decoration:underline;">{{ __('Open in the app') }}</a>
            </p>
            <p style="margin:0;font-size:11px;line-height:1.6;color:{{ $muted }};">{{ __('Whisper Money · Your data never leaves your account.') }}</p>
        </td></tr>
    </table>

</td></tr>
</table>
</body>
</html>
