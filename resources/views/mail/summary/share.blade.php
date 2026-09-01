{{-- The shareable card, above the figures rather than at the foot of the email.
     At the foot it was a postscript nobody reached; here it is the cover. The
     headline above is plain text, so a reader whose client blocks images still
     gets the month's number before anything has to load. --}}
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
                @if (count($alternatives) > 0)
                    <p style="margin:9px 0 0;font-size:11px;line-height:1.6;color:#a1a1aa;">{{ __('Or share something else:') }}
                        @foreach ($alternatives as $alternative)
                            <a href="{{ $alternative['url'] }}" style="color:#71717a;text-decoration:underline;">{{ $alternative['label'] }}</a>{{ $loop->last ? '' : ' · ' }}
                        @endforeach
                    </p>
                @endif
            </td>
        </tr></table>
    </td></tr>
</table>
