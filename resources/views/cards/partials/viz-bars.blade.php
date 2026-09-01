{{-- A year of monthly rates, the closed month picked out. No axis values: the
     shape is the story and the numbers are nobody else's business. --}}
<div class="bars" style="gap: {{ round(14 * $s) }}px; height: {{ round(210 * $s) }}px; margin-top: {{ ($wide ?? false) ? 0 : round(74 * $s) }}px">
    @foreach ($series as $point)
        <div style="height: {{ max(6, $point['height']) }}%; border-radius: {{ round(4 * $s) }}px; background: {{ $point['current'] ? $accent : ($point['recent'] ? $rule : $track) }}"></div>
    @endforeach
</div>
<div class="axis mono">
    <span>{{ $series[0]['label'] }}</span>
    <span>{{ $series[count($series) - 1]['label'] }}</span>
</div>
