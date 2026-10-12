#!/usr/bin/env sh
# Renders the landing product video in every locale × theme, plus a poster
# for each, into the Laravel app's public folder.
set -eu

cd "$(dirname "$0")/.."

command -v ffmpeg >/dev/null || { echo 'ffmpeg is required (brew install ffmpeg)' >&2; exit 1; }

OUTPUT_DIR=../public/images/landing_videos
# Dashboard scene, once every counter and sparkline has settled.
POSTER_FRAME=670
CRF=${CRF:-20}

# Bundle and intermediate renders live outside the repo, where eslint never sees them.
WORK_DIR=$(mktemp -d)
trap 'rm -rf "$WORK_DIR"' EXIT

mkdir -p "$OUTPUT_DIR"
bunx remotion bundle --out-dir="$WORK_DIR/bundle"

for locale in en es; do
    for theme in dark light; do
        name="product-$locale-$theme"
        props="{\"locale\":\"$locale\",\"theme\":\"$theme\"}"

        bunx remotion render "$WORK_DIR/bundle" LandingVideo "$WORK_DIR/$name.mp4" \
            --props="$props" --codec=h264 --crf="$CRF" --pixel-format=yuv420p --muted
        # Moov atom first so the browser starts playing before the download ends.
        ffmpeg -y -loglevel error -i "$WORK_DIR/$name.mp4" -c copy -an -movflags +faststart "$OUTPUT_DIR/$name.mp4"

        bunx remotion still "$WORK_DIR/bundle" LandingVideo "$OUTPUT_DIR/$name.jpg" \
            --props="$props" --frame="$POSTER_FRAME" --image-format=jpeg --jpeg-quality=80
    done
done
