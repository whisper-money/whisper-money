@props([
    'cid',
    'alt' => '',
    'width' => 240,
])
<table class="medal" align="center" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center" style="padding: 8px 0 4px;">
<img src="cid:{{ $cid }}" alt="{{ $alt }}" width="{{ $width }}" style="display: block; width: {{ $width }}px; max-width: 100%; height: auto; border-radius: 12px;">
</td>
</tr>
</table>
