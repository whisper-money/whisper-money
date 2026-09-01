{{-- The same year, with the streak underlined so the run reads at a glance. --}}
<div class="bars" style="gap: {{ round(12 * $s) }}px; height: {{ round(150 * $s) }}px; margin-top: {{ ($wide ?? false) ? 0 : round(58 * $s) }}px">
    @foreach ($series as $point)
        <div style="height: {{ max(6, $point['height']) }}%; border-radius: {{ round(6 * $s) }}px; background: {{ $point['in_streak'] ? $accent : $track }}"></div>
    @endforeach
</div>
<div class="bars" style="gap: {{ round(12 * $s) }}px; margin-top: {{ round(14 * $s) }}px">
    @foreach ($series as $point)
        <div style="height: {{ round(8 * $s) }}px; border-radius: {{ round(4 * $s) }}px; background: {{ $point['in_streak'] ? $accent : 'transparent' }}"></div>
    @endforeach
</div>
<div class="axis mono">
    <span>{{ $series[0]['label'] }}</span>
    <span>{{ $series[count($series) - 1]['label'] }}</span>
</div>
