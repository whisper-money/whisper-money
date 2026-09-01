{{-- One goal, one bar. The target amount is not on it. --}}
<div style="margin-top: {{ ($wide ?? false) ? 0 : round(76 * $s) }}px">
    <div style="height: {{ round(72 * $s) }}px; border-radius: {{ round(10 * $s) }}px; background: {{ $track }}; overflow: hidden">
        <div style="width: {{ $percent }}%; height: 100%; border-radius: {{ round(10 * $s) }}px; background: {{ $ink }}"></div>
    </div>
    <div class="axis mono" style="margin-top: {{ round(14 * $s) }}px">
        <span>{{ $from }}</span>
        <span>{{ $to }}</span>
    </div>
</div>
