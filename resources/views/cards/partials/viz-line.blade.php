{{-- Twelve months of net worth, normalised into the box. Deliberately unlabelled
     on both axes: the direction is shareable, the balance is not. --}}
<div style="margin-top: {{ ($wide ?? false) ? 0 : round(76 * $s) }}px">
    <svg width="100%" height="{{ round(290 * $s) }}" viewBox="0 0 {{ $viewWidth }} 300" preserveAspectRatio="none" fill="none">
        <path d="{{ $path }}" stroke="{{ $ink }}" stroke-width="{{ max(3, round(6 * $s)) }}" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>
    </svg>
</div>
<div class="axis mono">
    <span>{{ $from }}</span>
    <span>{{ $to }}</span>
</div>
