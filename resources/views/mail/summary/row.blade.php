{{-- One sentence, one chart. The rule above each row is what the design uses to
     separate them, so the first row has one too. --}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-top:1px solid #e4e4e7;">
    <tr>
        <td valign="top" style="padding:17px 26px 17px 0;font-size:15px;line-height:1.5;color:#52525b;">{!! $row['text'] !!}</td>
        <td valign="top" width="170" style="padding:20px 0 17px;">
            @include('mail.summary.viz', ['viz' => $row['viz'], 'data' => $row['data']])
        </td>
    </tr>
</table>
