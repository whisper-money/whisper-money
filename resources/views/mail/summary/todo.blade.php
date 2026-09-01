{{-- One thing to do, numbered. Deliberately no icon: inline SVG is stripped by
     Gmail and an SVG served as an image does not render there either, so the
     alternative was three PNG assets for three list markers. A number also reads
     better in a list of three things than three different pictograms. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-top:1px solid #e4e4e7;">
    <tr>
        <td valign="top" width="43" style="padding:16px 13px 15px 0;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="28"><tr>
                <td align="center" height="28" style="border:1px solid #e4e4e7;border-radius:4px;font-size:13px;font-weight:600;color:#18181b;line-height:28px;">{{ $number }}</td>
            </tr></table>
        </td>
        <td valign="top" style="padding:15px 0;">
            <p style="margin:0;font-size:14px;line-height:1.45;color:#52525b;">{!! $todo['text'] !!}</p>
            <a href="{{ $todo['url'] }}" style="display:inline-block;margin-top:5px;font-size:13px;font-weight:600;color:#18181b;text-decoration:none;border-bottom:1px solid #d4d4d8;">{{ $todo['action'] }}</a>
        </td>
    </tr>
</table>
