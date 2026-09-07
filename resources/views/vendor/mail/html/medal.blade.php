@props([
    'lines' => [],
])
@php
    // Two per row. The cells split the content cell evenly and each one gives
    // half the gutter back as padding, so both cards come out the same width
    // whatever the client makes of the table: a column asked for 240px and a
    // neighbour asked for 240px plus a gutter are not the same column, and the
    // card that loses the argument is the one that gets scaled down.
    //
    // A third medal starts a second row at that same width rather than
    // stretching across: a card wider than its neighbours reads as a mistake,
    // a short row reads as a grid.
    $cards = array_values(array_filter($lines, fn (array $line): bool => $line['card'] !== null));
    $rows = array_chunk($cards, 2);
    $alone = count($cards) === 1;
@endphp
@if ($rows !== [])
<table class="medals" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 8px 0 4px;">
@foreach ($rows as $row)
<tr>
@foreach ($row as $card)
<td class="medal-cell" valign="top" align="{{ $alone ? 'center' : 'left' }}" width="{{ $alone ? '100%' : '50%' }}" style="width: {{ $alone ? '100%' : '50%' }};{{ $alone ? '' : ($loop->first ? ' padding-right: 13px;' : ' padding-left: 13px;') }}{{ $loop->parent->last ? '' : ' padding-bottom: 20px;' }}">
<img src="cid:{{ $card['card'] }}" alt="{{ $card['name'] }}" width="240" style="display: block; width: 240px; max-width: 100%; height: auto; border: 1px solid #e4e4e7; border-radius: 4px;{{ $alone ? ' margin: 0 auto;' : '' }}">
</td>
@endforeach
</tr>
@endforeach
</table>
@endif
