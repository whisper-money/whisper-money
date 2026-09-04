{{--
    The medal, struck at whatever size the card gives it.

    The same drawing as `resources/js/components/achievements/medal.tsx`: a
    five-stop face ramp, a bevelled inner edge, one specular sweep and the
    pictogram engraved rather than painted. Geometry and palette both come off
    {@see \App\Enums\AchievementRarity} so the card and the screen cannot
    disagree about what a tier looks like.

    Expects: $rarity (AchievementRarity), $glyph (raw SVG paths), $size (px).
--}}
@php
    $metal = $rarity->metal();
    $shape = $rarity->medalShape();
    $bezel = $metal['bezel']?->metal();

    // Ids are scoped to this medal: a card draws one, but the same view is
    // photographed in batches and a collision would swap two metals over.
    $id = 'm'.substr(md5($rarity->value.$size.($glyph ?? '')), 0, 8);

    $face = $shape['face'];
    $glyphPx = ($shape['glyph'] / 48) * $size;

    // The engraved copy sits a fraction of the glyph below the ink. Same ratio
    // as the screen, which is what reads as stamped instead of double-printed.
    $offset = max(0.4, $glyphPx / 26);
    $lit = $bezel !== null ? 'rgba(0,0,0,0.55)' : 'rgba(255,255,255,0.62)';

    $crown = collect(range(0, 35))
        ->map(function (int $index): string {
            $angle = (M_PI * $index) / 18 - M_PI / 2;
            $radius = $index % 2 === 0 ? 23.5 : 20.5;

            return number_format(24 + $radius * cos($angle), 2, '.', '').','.number_format(24 + $radius * sin($angle), 2, '.', '');
        })
        ->implode(' ');
@endphp
<span style="position: relative; display: inline-flex; width: {{ $size }}px; height: {{ $size }}px">
    <svg viewBox="0 0 48 48" width="{{ $size }}" height="{{ $size }}" style="display: block">
        <defs>
            @foreach (['' => $metal, 'z' => $bezel] as $key => $stops)
                @if ($stops !== null)
                    <linearGradient id="{{ $id }}{{ $key }}-face" x1="0.12" y1="0" x2="0.82" y2="1">
                        <stop offset="0" stop-color="{{ $stops['hi'] }}"/>
                        <stop offset="0.28" stop-color="{{ $stops['light'] }}"/>
                        <stop offset="0.52" stop-color="{{ $stops['core'] }}"/>
                        <stop offset="0.78" stop-color="{{ $stops['shadow'] }}"/>
                        <stop offset="1" stop-color="{{ $stops['bounce'] }}"/>
                    </linearGradient>
                @endif
            @endforeach
            <linearGradient id="{{ $id }}-bevel" x1="0.2" y1="0" x2="0.78" y2="1">
                <stop offset="0" stop-color="#fff" stop-opacity="0.6"/>
                <stop offset="0.4" stop-color="#fff" stop-opacity="0"/>
                <stop offset="0.62" stop-color="#000" stop-opacity="0"/>
                <stop offset="1" stop-color="#000" stop-opacity="0.34"/>
            </linearGradient>
            <radialGradient id="{{ $id }}-sheen" cx="0.5" cy="0.5" r="0.5">
                <stop offset="0" stop-color="#fff" stop-opacity="{{ $metal['sheen'] }}"/>
                <stop offset="1" stop-color="#fff" stop-opacity="0"/>
            </radialGradient>
            <clipPath id="{{ $id }}-clip">
                <circle cx="24" cy="24" r="{{ $face }}"/>
            </clipPath>
        </defs>

        @if ($shape['crown'])
            <polygon points="{{ $crown }}" fill="url(#{{ $id }}{{ $bezel !== null ? 'z' : '' }}-face)"/>
            <polygon points="{{ $crown }}" fill="none" stroke="{{ ($bezel ?? $metal)['rim'] }}" stroke-opacity="0.45" stroke-width="0.75"/>
        @endif

        @if ($shape['ring'])
            <circle cx="24" cy="24" r="22.25" fill="none" stroke="url(#{{ $id }}-face)" stroke-width="1.5"/>
            <circle cx="24" cy="24" r="22.25" fill="none" stroke="{{ $metal['rim'] }}" stroke-opacity="0.35" stroke-width="0.5"/>
        @endif

        <circle cx="24" cy="24" r="{{ $face }}" fill="url(#{{ $id }}-face)"/>

        <g clip-path="url(#{{ $id }}-clip)">
            <ellipse cx="18.5" cy="16.5" rx="13" ry="8.5" fill="url(#{{ $id }}-sheen)" transform="rotate(-32 18.5 16.5)"/>
        </g>

        <circle cx="24" cy="24" r="{{ $face - 0.75 }}" fill="none" stroke="url(#{{ $id }}-bevel)" stroke-width="1.5"/>
        <circle cx="24" cy="24" r="{{ $face - 0.35 }}" fill="none" stroke="{{ $metal['rim'] }}" stroke-opacity="0.5" stroke-width="0.7"/>

        @if ($bezel !== null)
            <circle cx="24" cy="24" r="{{ $face - 2.6 }}" fill="none" stroke="{{ $bezel['core'] }}" stroke-opacity="0.8" stroke-width="0.9"/>
        @endif
    </svg>

    @foreach ([[$lit, $offset], [$metal['ink'], 0]] as [$stroke, $dy])
        <svg viewBox="0 0 24 24" width="{{ $glyphPx }}" height="{{ $glyphPx }}"
             fill="none" stroke="{{ $stroke }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
             style="position: absolute; top: 50%; left: 50%; translate: -50% calc(-50% + {{ $dy }}px)">{!! $glyph !!}</svg>
    @endforeach
</span>
