{{-- The shareable card: the second way into the app, after the report. It
     carries no amount either, so it can sit in the email as it is. Both the
     picture and the button land on the report's share section. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:26px;border:1px solid #e4e4e7;border-radius:4px;background:#fafafa;">
    <tr><td style="padding:20px;">
        <table role="presentation" class="stack" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>
            @if ($cardUrl !== null)
                <td valign="top" width="176" style="padding-right:18px;">
                    <a href="{{ $shareUrl }}"><img src="{{ $cardUrl }}" width="176" alt="{{ $cardAlt }}" style="display:block;width:176px;max-width:176px;height:auto;border:1px solid #e4e4e7;border-radius:3px;"></a>
                </td>
            @endif
            <td valign="top" class="stack-gap">
                <p style="margin:0;font-size:10px;font-weight:600;letter-spacing:0.09em;text-transform:uppercase;color:#a1a1aa;">{{ __('Your :month card', ['month' => $monthName]) }}</p>
                <p style="margin:8px 0 0;font-size:14px;line-height:1.5;color:#52525b;">{{ $shareBlurb }}</p>
                <p style="margin:13px 0 0;"><a href="{{ $shareUrl }}" style="display:inline-block;background:#18181b;color:#ffffff;font-size:13px;font-weight:600;padding:10px 16px;border-radius:4px;text-decoration:none;">{{ __('Get the card') }}</a></p>
            </td>
        </tr></table>
    </td></tr>
</table>
