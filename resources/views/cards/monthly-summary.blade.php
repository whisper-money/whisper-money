{{--
    One template for every shareable card: five kinds by three formats.

    Kept as a single parameterised view on purpose. Fifteen near-identical files
    would trip the duplication check that gates the merge, and a change to the
    footer lockup would then have to be made fifteen times.

    Not a single absolute amount appears here. Percentages, streaks and counts
    are what a person shares without thinking twice; their net worth is not, and
    the card only exists because it is safe to post.
--}}
@php
    [$width, $height] = $format->dimensions();
    $dark = $format === \App\Enums\MonthlySummaryFormat::Story;
    $wide = $format === \App\Enums\MonthlySummaryFormat::Wide;

    // One scale factor drives the whole type ramp, so the 16:9 card is the same
    // design at 3/4 size rather than a second layout to maintain.
    $s = $wide ? 0.66 : 1.0;
    $ink = $dark ? '#fafafa' : '#18181b';
    $muted = $dark ? '#71717a' : '#a1a1aa';
    $soft = $dark ? '#a1a1aa' : '#52525b';
    $rule = $dark ? '#3f3f46' : '#e4e4e7';
    $track = $dark ? '#27272a' : '#f4f4f5';
    $accent = $dark ? '#34d399' : '#059669';
    $pad = $wide ? 64 : 84;

    // The hero is one line and must never leave its column, and CSS cannot fit
    // text to a box without JavaScript. So the size is bounded by the room
    // available: "+73,1 %" is seven glyphs and needs a smaller face than "2".
    // 0.56em is the widest average advance IBM Plex Sans Bold produces for the
    // digits, comma, space and percent sign a hero is made of.
    $heroColumn = $wide ? 0.48 * (1200 - $pad * 2) : 1080 - $pad * 2;
    $heroFit = $heroColumn / max(1, mb_strlen($hero) * 0.56);
    $heroPx = (int) round(min($heroSize * $s, $heroFit));
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap">
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        .card {
            width: {{ $width }}px;
            height: {{ $height }}px;
            padding: {{ $pad }}px;
            background: {{ $dark ? '#18181b' : '#ffffff' }};
            color: {{ $ink }};
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-family: 'IBM Plex Sans', -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif;
            font-variant-numeric: tabular-nums;
        }
        .mono { font-family: 'IBM Plex Mono', ui-monospace, Menlo, monospace; }
        .top { display: flex; align-items: baseline; justify-content: space-between; gap: {{ 24 * $s }}px; }
        .eyebrow {
            font-size: {{ round(26 * $s) }}px;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: {{ $muted }};
        }
        .hero { margin: 0; line-height: 0.82; font-weight: 700; letter-spacing: -0.055em; }
        .label {
            margin: {{ round(30 * $s) }}px 0 0;
            font-size: {{ round(46 * $s) }}px;
            line-height: 1.2;
            font-weight: 500;
            letter-spacing: -0.02em;
            color: {{ $soft }};
            text-wrap: pretty;
        }
        .label strong { color: {{ $ink }}; font-weight: 600; }
        .axis {
            display: flex;
            justify-content: space-between;
            margin-top: {{ round(18 * $s) }}px;
            font-size: {{ round(22 * $s) }}px;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: {{ $muted }};
        }
        .chips { display: flex; gap: {{ round(16 * $s) }}px; flex-wrap: wrap; }
        .chip {
            border: {{ max(2, round(2 * $s)) }}px solid {{ $rule }};
            border-radius: 999px;
            padding: {{ round(15 * $s) }}px {{ round(26 * $s) }}px;
            font-size: {{ round(27 * $s) }}px;
            font-weight: 500;
            color: {{ $soft }};
        }
        .chip strong { color: {{ $ink }}; font-weight: 600; }
        .foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: {{ round(24 * $s) }}px;
            margin-top: {{ round(46 * $s) }}px;
            padding-top: {{ round(40 * $s) }}px;
            border-top: {{ max(2, round(2 * $s)) }}px solid {{ $rule }};
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: {{ round(12 * $s) }}px;
            background: {{ $dark ? '#fafafa' : '#18181b' }};
            color: {{ $dark ? '#18181b' : '#ffffff' }};
            border-radius: 999px;
            padding: {{ round(13 * $s) }}px {{ round(24 * $s) }}px {{ round(13 * $s) }}px {{ round(20 * $s) }}px;
            font-size: {{ round(23 * $s) }}px;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }
        .lockup { display: flex; align-items: center; gap: {{ round(15 * $s) }}px; }
        .lockup span { font-size: {{ round(38 * $s) }}px; font-weight: 600; letter-spacing: -0.02em; }
        .bars { display: flex; align-items: flex-end; }
        .bars > div { flex-grow: 1; }
        .rank { display: flex; align-items: center; gap: {{ round(20 * $s) }}px; padding: {{ round(20 * $s) }}px 0; border-bottom: 2px solid {{ $track }}; }
        .rank-dot { width: {{ round(24 * $s) }}px; height: {{ round(24 * $s) }}px; border-radius: {{ round(6 * $s) }}px; flex-shrink: 0; }
        .rank-name { flex-grow: 1; font-size: {{ round(31 * $s) }}px; font-weight: 500; }
        .rank-pct { font-size: {{ round(31 * $s) }}px; font-weight: 700; letter-spacing: -0.02em; }
        .body { display: flex; flex-direction: column; }
        .body-viz { min-width: 0; }
        @if ($format === \App\Enums\MonthlySummaryFormat::Story)
            /* Nearly twice as tall as the feed card, so the figure is centred
               rather than stranded halfway down a field of background. */
            .body { flex-grow: 1; justify-content: center; }
        @endif
        @if ($wide)
            /* Side by side, because stacked at this height the footer falls off
               the bottom of the frame. */
            .body { flex-direction: row; align-items: flex-end; gap: {{ round(52 * $s) }}px; flex-grow: 1; }
            .body-hero { width: 48%; flex-shrink: 0; }
            .body-viz { flex-grow: 1; }
            .label { margin-top: {{ round(22 * $s) }}px; }
        @endif
    </style>
</head>
<body>
<div class="card">

    <div class="top">
        <span class="eyebrow mono">{{ $monthLabel }}</span>
        <span class="eyebrow mono">{{ $kicker }}</span>
    </div>

    <div class="body">
        <div class="body-hero">
            <p class="hero" style="font-size: {{ $heroPx }}px">{{ $hero }}</p>
            <p class="label">{!! $label !!}</p>
        </div>

        <div class="body-viz">
            @include('cards.partials.'.$viz, [
                's' => $s, 'accent' => $accent, 'track' => $track, 'ink' => $ink,
                'muted' => $muted, 'rule' => $rule, 'dark' => $dark, 'wide' => $wide,
            ])
        </div>
    </div>

    <div>
        @if (count($chips) > 0)
            <div class="chips">
                @foreach ($chips as $chip)
                    <span class="chip">{!! $chip !!}</span>
                @endforeach
            </div>
        @endif

        <div class="foot">
            @if ($pro)
                <span class="badge mono">
                    @include('cards.partials.icon-sparkle', ['size' => round(26 * $s), 'colour' => $dark ? '#18181b' : '#ffffff', 's' => $s])
                    {{ __('Pro member') }}
                </span>
            @else
                <span></span>
            @endif

            <span class="lockup">
                @include('cards.partials.icon-bird', ['size' => round(42 * $s), 'colour' => $ink])
                <span class="mono">whisper.money</span>
            </span>
        </div>
    </div>

</div>
</body>
</html>
