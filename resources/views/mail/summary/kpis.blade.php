{{-- Up to three tiles, percentages and counts only. The savings rate is not
     one of them: the headline above already says it.

     One table cell per tile with a fixed share of the width, which Outlook
     respects where it would ignore a grid. On a phone the cells stack, and the
     rule between them moves from the left edge to the top. --}}
@php
    $tones = ['good' => '#059669', 'bad' => '#dc2626'];
    $share = (int) floor(100 / count($kpis));
@endphp
<table role="presentation" class="stack" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-top:24px;border-top:1px solid #e4e4e7;border-bottom:1px solid #e4e4e7;"><tr>
    @foreach ($kpis as $kpi)
        <td valign="top" width="{{ $share }}%" class="{{ $loop->first ? '' : 'kpi-next' }}" style="padding:16px 12px 15px {{ $loop->first ? '0' : '16px' }};{{ $loop->first ? '' : 'border-left:1px solid #e4e4e7;' }}">
            <p style="margin:0;font-size:22px;line-height:1.2;font-weight:700;letter-spacing:-0.02em;color:{{ $tones[$kpi['tone']] ?? '#18181b' }};">{{ $kpi['value'] }}</p>
            <p style="margin:3px 0 0;font-size:12px;font-weight:600;color:#18181b;">{{ $kpi['label'] }}</p>
            <p style="margin:2px 0 0;font-size:11px;color:#a1a1aa;">{{ $kpi['sub'] }}</p>
        </td>
    @endforeach
</tr></table>
