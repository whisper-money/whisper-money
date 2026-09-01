{{-- The small chart that sits beside a sentence. Tables and inline styles only:
     this has to survive Outlook, where flexbox does not exist. --}}
@php $legend = fn (?string $left, ?string $right) => [$left, $right]; @endphp

@if ($viz === 'sparkline')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="170"><tr>
        @foreach ($data['points'] as $index => $point)
            <td valign="bottom" height="44" style="padding:0 1px;">
                <div style="height:{{ max(2, (int) round($point * 0.40) + 3) }}px;background:{{ $index === count($data['points']) - 1 ? '#18181b' : '#d4d4d8' }};border-radius:1px;font-size:0;line-height:0;">&nbsp;</div>
            </td>
        @endforeach
    </tr></table>
@elseif ($viz === 'bar')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="170"><tr>
        @foreach ($data['segments'] as $segment)
            @continue($segment['width'] <= 0)
            <td width="{{ (int) round($segment['width']) }}%" height="10" style="background:{{ $segment['colour'] }};font-size:0;line-height:0;">&nbsp;</td>
        @endforeach
        <td height="10" style="background:#f4f4f5;font-size:0;line-height:0;">&nbsp;</td>
    </tr></table>
@elseif ($viz === 'columns')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
        @foreach ($data['columns'] as $column)
            <td valign="bottom" width="46" height="46" style="padding-right:8px;">
                <div style="height:{{ max(4, (int) round($column['height'] * 0.46)) }}px;background:{{ $column['colour'] }};border-radius:2px;font-size:0;line-height:0;">&nbsp;</div>
            </td>
        @endforeach
    </tr></table>
@elseif ($viz === 'dots')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="170"><tr>
        @for ($i = 0; $i < $data['met']; $i++)
            <td height="22" style="padding-right:4px;"><div style="height:22px;background:#059669;border-radius:2px;font-size:0;line-height:0;">&nbsp;</div></td>
        @endfor
        @for ($i = 0; $i < $data['over']; $i++)
            <td height="22" style="padding-right:4px;"><div style="height:22px;background:#dc2626;border-radius:2px;font-size:0;line-height:0;">&nbsp;</div></td>
        @endfor
    </tr></table>
@endif

@if (($data['left'] ?? null) || ($data['right'] ?? null) || $viz === 'columns')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="170" style="margin-top:7px;"><tr>
        @if ($viz === 'columns')
            @foreach ($data['columns'] as $column)
                <td width="46" style="padding-right:8px;font-size:10px;color:#a1a1aa;">{{ $column['label'] }}</td>
            @endforeach
        @else
            <td align="left" style="font-size:10px;color:#71717a;font-weight:600;">{{ $data['left'] ?? '' }}</td>
            <td align="right" style="font-size:10px;color:#a1a1aa;">{{ $data['right'] ?? '' }}</td>
        @endif
    </tr></table>
@endif
