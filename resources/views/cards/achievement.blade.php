{{--
    One shareable medal: any of the catalog's medals, by two formats and two
    themes.

    One parameterised view for the same reason the monthly summary is one: a
    file per medal would be forty-six near-identical templates, which both trips
    the duplication check that gates the merge and means the footer lockup has
    to be changed forty-six times.

    The medal is the subject, so unlike the summary card there is no hero
    number: the disc is the hero and the figure sits under it as a caption. The
    tier is named; the share of members holding it is NOT, because that number
    is for inside the app only ({@see \App\Enums\AchievementRarity}).

    The figure is drawn when $figure is present. For a money medal that is the
    reader's own decision — the share dialog can withhold it — which is why this
    view never reads the amount from anywhere but $figure.
--}}
@php
    [$width, $height] = $format->dimensions();
    $dark = $theme->isDark();
    $story = $format === \App\Enums\CardFormat::Story;

    $ink = $dark ? '#fafafa' : '#18181b';
    $muted = $dark ? '#71717a' : '#a1a1aa';
    $soft = $dark ? '#a1a1aa' : '#52525b';
    $rule = $dark ? '#3f3f46' : '#e4e4e7';
    $pad = 84;

    // The medal is the whole point, so it takes the room the format gives it.
    // Big enough to be the subject rather than an illustration of one: at 340
    // in a 1080 frame it read as a bullet point next to the words.
    $medalSize = $story ? 600 : 400;

    // The name is one or two lines and must not leave its column. Same trick as
    // the summary card: bound the size by the room rather than reach for JS.
    $column = $width - $pad * 2;
    $nameFit = $column / max(1, mb_strlen($name) * 0.46);
    $namePx = (int) round(min(76, $nameFit));
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
        .top { display: flex; align-items: baseline; justify-content: space-between; gap: 24px; }
        .eyebrow {
            font-size: 26px;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: {{ $muted }};
        }
        /* The block sits against the footer rather than floating in the middle
           of the column: centred, it left a pocket of dead air above AND below,
           and two accidental gaps read as a mistake where one deliberate one
           reads as space. The story card is tall enough to centre instead — at
           1920 an anchored block strands nine hundred pixels of background. */
        .body { display: flex; flex-direction: column; flex-grow: 1; justify-content: flex-end; gap: 52px; }
        .figure {
            margin: 0 0 18px;
            font-size: 104px;
            line-height: 0.88;
            font-weight: 700;
            letter-spacing: -0.045em;
        }
        .name {
            margin: 0;
            line-height: 1.08;
            font-weight: 600;
            letter-spacing: -0.03em;
            text-wrap: pretty;
        }
        .tier {
            display: inline-flex;
            align-items: center;
            margin-top: 30px;
            gap: 16px;
            font-size: 27px;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }
        .tier-rule { width: 46px; height: 3px; border-radius: 999px; }
        .when { margin: 0; font-size: 34px; font-weight: 500; color: {{ $soft }}; }
        .foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            margin-top: 46px;
            padding-top: 40px;
            border-top: 2px solid {{ $rule }};
        }
        .lockup { display: flex; align-items: center; gap: 15px; }
        .lockup span { font-size: 38px; font-weight: 600; letter-spacing: -0.02em; }
        @if ($story)
            .body { justify-content: center; gap: 64px; }
        @endif
    </style>
</head>
<body>
<div class="card">

    <div class="top">
        <span class="eyebrow mono">{{ $track }}</span>
        <span class="eyebrow mono">{{ $when }}</span>
    </div>

    <div class="body">
        @include('cards.partials.medal', ['rarity' => $rarity, 'glyph' => $glyph, 'size' => $medalSize])

        <div>
            @if ($figure !== null)
                <p class="figure">{{ $figure }}</p>
            @endif
            <p class="name" style="font-size: {{ $namePx }}px">{{ $name }}</p>

            <span class="tier mono" style="color: {{ $tierColour }}">
                <span class="tier-rule" style="background: {{ $tierColour }}"></span>
                {{ $tier }}
            </span>
        </div>
    </div>

    <div class="foot">
        <span></span>
        <span class="lockup">
            @include('cards.partials.icon-bird', ['size' => 42, 'colour' => $ink])
            <span class="mono">whisper.money</span>
        </span>
    </div>

</div>
</body>
</html>
