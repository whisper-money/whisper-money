# Review

Storyline B, "Infinite zoom", 33.5 s, rendered in English and Spanish from the
same `index.html` (Spanish: `--variables-file variables.es.json`).

## Creative calls

- **The hook is a fourth widget.** Frame 0 is the real "weekly shop" widget; the
  camera dives into its tallest bar and finds the first ask. Each later widget
  then opens into the next person's ask, so every cycle is sentence → code →
  a widget nobody had before.
- **Each cycle's ground is the colour of the mark the camera dove into**,
  sampled from the captures (emerald bar, amber Friday bar, the car loan's pale
  blue area), so the dive lands on a flat field with no cut.
- **The pull-back is the same pixels.** The kids widget capture is placed exactly
  over its cell in the dashboard capture, then the camera pulls back to the
  whole "Home" dashboard.
- **The code is texture, not a read.** The agent card types eight lines copied
  verbatim from the saved widget source (`scripts/code-excerpts.py`), faster on
  every cycle. `check` flags 21 `line_unread` warnings on those lines; kept on
  purpose, the asks and the widgets carry the meaning.
- **The asks are illustrative** (Maya, Tom, Priya / Lucía, Javi, Marta);
  every widget, number and label on screen is the local promo account's real
  app.
- **One file, not one scene per sub-composition.** `--variables` only reaches
  the root composition, so the copy and captures are root variables and the
  scenes live in one file. `check` warns about that (`nested_structure_*`,
  `composition_file_too_large`); kept so one render switches language.
- Not built here: storylines A and C (`STORYLINES.md`), other agents' films.

## What was measured, and what was only looked at

- `npx hyperframes check --json`: no errors. Warnings kept: the ones above.
- `snapshot --review` sheets, plus frames of both rendered MP4s at the SPEC QA
  times (`~/Movies/whisper-money-promos/dashboards-infinite-zoom-contact-sheet.jpg`).
- Sound, measured on both renders with `npx hyperframes audio`: −15.6 LUFS
  integrated, −1.0 dBTP true peak, no clipping; onsets line up with the dives
  (1.6, 9.0, 15.0) and the kicks speed up per cycle. **Nobody heard it.**
- Text sizes: asks 64 px, claim 116/104 px, code 28 px, end-card note 30 px.

## Notes fixed during review

- t=1.5 s · hook dive · a white strip at the bar's edge in the last frames →
  dive scale 16 → 26.
- t=3.0 s · ask 1 · broke after "at" → bubble max width 1340 → 1640 px.
- t=27.5 s · second claim · 1.4 s on screen → held 2.3 s; film 32.5 → 33.5 s.
- t=25.5 s (ES) · the accent coloured "pidieras." with its full stop → only
  the word takes the accent.
- Captures: a chart tooltip stayed open in the kids widget → the capture script
  moves the pointer away and hides tooltips.

## Weakest shot

The code beats (4.4–6.0 s, 11.2–12.4 s, 17.0–18.0 s): a dark card of small mono
text on a bright field. With more time: fewer, larger lines (the manifest's
data call and the title line) and a cut straight to the widget building.

## How it was made

1. **Accounts** (local only, never the production connector): `promo-b-en` and
   `promo-b-es@whisper.local`, locale `en`/`es`, an `sub_e2e_` subscription
   (Standard plan, kept out of metrics), the `CustomDashboards` flag, 12 months
   of transactions (takeaway weighted to Friday-Sunday, a €18,000 car loan at
   €345 a month since June 2024, swimming, football and piano since September
   2025). Seeded from tinker; the seeding code is not part of this project.
2. **Widgets**, written by the agent making this film and saved through
   `WidgetDefinitionService::create()` and `place()` (what `create_widget` and
   `place_widget` call), so they were validated and compiled like any user's.
   Their saved sources are in `assets/widgets/`.
3. **Captures**: `APP_BASE_URL=http://127.0.0.1:<port> node
videos/dashboards-infinite-zoom/scripts/capture.mjs` (headless Chrome,
   1440 × 810 at 3x, light mode), which also writes `layout.json` with every
   widget's and bar's position, used for the dive targets.
4. **Sound**: `assets/audio/pad-musicgen.flac` was generated locally with
   `facebook/musicgen-small`, seed 7, prompt "warm ambient synth pad, slow
   evolving chords, bright and hopeful, minimal, no drums, no percussion, clean
   modern production". `scripts/build-score.py` adds the kick pulse (the attack
   of the bundled `impact-bass-1`) and writes `assets/audio/score.m4a`. The
   effects come from the HyperFrames bundled library (Pixabay licence,
   `assets/sfx/CREDITS.md`).
5. **Render**: `npx hyperframes render --quality delivery -o
renders/dashboards-infinite-zoom-en.mp4`, and for Spanish add
   `--strict-variables --variables-file variables.es.json`.
