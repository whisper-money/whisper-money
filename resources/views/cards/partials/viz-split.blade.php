{{-- Shares of the month's spending. Names and percentages only: what was spent
     stays in the app. --}}
<div style="display: flex; height: {{ round(64 * $s) }}px; border-radius: {{ round(8 * $s) }}px; overflow: hidden; margin-top: {{ ($wide ?? false) ? 0 : round(68 * $s) }}px">
    @foreach ($rows as $row)
        <div style="width: {{ $row['share'] }}%; background: {{ $row['colour'] }}"></div>
    @endforeach
</div>
<div style="margin-top: {{ round(34 * $s) }}px">
    @foreach ($rows as $row)
        <div class="rank">
            <span class="rank-dot" style="background: {{ $row['colour'] }}"></span>
            <span class="rank-name">{{ $row['name'] }}</span>
            <span class="rank-pct">{{ $row['label'] }}</span>
        </div>
    @endforeach
</div>
