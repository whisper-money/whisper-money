# REVIEW — One dashboard per person

## What was built

A 33.75 s, 1920 x 1080, 30 fps film from one composition (`index.html`), rendered
twice: `--variables variables/en.json` and `--variables variables/es.json`. The `lang`
variable picks the folder of captures (`assets/en`, `assets/es`); every line of copy is
its own variable.

## Creative calls

- Grounds: amber, emerald and blue 300 tones, the app's colorful chart palette, one per
  person. Ink stays #1F1F1F on all three; the only accent is the emerald URL.
- The panes show the **phone** layout of each widget (430 css px at 3x). At a third of
  the frame the desktop cards would put 14 px text at ~8 px; the phone captures land at
  1.47x their css size. Each pane scrolls like a phone as its dashboard grows, then pulls
  back to show the whole dashboard.
- The merge opens the real empty dashboard from the exact rectangle of the middle pane
  and grows it over the outer two, which are pulled in under it. The question sits on
  the dashboard's own empty canvas, not over a dimmed screenshot.
- The end card fills the logo tile with the three grounds and then white, so the bird
  lands on its own tile; the wordmark wipes in after it.
- Music: three synthesized layers on one 96 BPM grid (`scripts/music.mjs`): pad from 0 s
  (Maya), arpeggio from 5 s (the family), rhythm from 7.5 s (Leo); all three to the end.
- The other storylines (A, B) belong to their own agents and were not built here.

## The product, for real

Six local users `promo-c-{freelancer,family,traveller}-{en,es}@whisper.local`, Standard
plan (seeded subscription), CustomDashboards flag on, seven months of transactions each.
Nine widgets per language written as TSX and saved through `WidgetDefinitionService`
(the path `create_widget` / `place_widget` use, origin `Mcp`): compiled, validated and
placed. The validator rejected two first drafts (computed brackets, a non-literal prop)
and the dashboard caught two runtime errors (the API answer is wrapped in `data`); those
were fixed the way an agent would. Captures: headless Chrome via Playwright.

## Measured

- `npx hyperframes check --json`: ok. Kept warnings:
  `nested_structure_needs_subcomposition` (x3): the scenes live in the root file on
  purpose, because a CLI `--variables` render only reaches the root composition and every
  scene needs the language copy.
- `snapshot --review`: beats, seams and phone sheet read; frames at 0, 8.5, 12, 15.5,
  18.4, 20.5, 21.6, 24, 27.7 and 31 s checked against SPEC.md.
- Rendered audio (`hyperframes audio`): −14.4 LUFS integrated, −1.0 dBTP, no clipping,
  onsets on the bubbles (2.5 / 5.0 / 7.5 s) and the impact (27 s). Measured, not heard.

## Fixed during review

- P0 21.25 s · the merge showed a dark empty frame (the world's fill covered the panes) →
  fill moved to the clipped camera, world opens from the middle pane.
- P1 12 s · the family pane scrolled halfway through "The Parkers" → once a pane scrolls,
  it scrolls past the whole header.
- P1 widgets on a phone: tax tiles overlapped and a chart's first tick was clipped →
  widgets rewritten (2-column tiles on narrow widths, chart margins), recaptured.
- P1 ES captures showed English month names → the ES users got `format_locale = es-ES`,
  as a Spanish user has.

## Weakest shot and what is left

- 10–11 s: the agent bubble briefly overlaps the top of the dashed placeholder before it
  fades (0.9 s). With more time: shorten the placeholder when the sentence arrives.
- After a pane scrolls, a sliver of the cut-off chat bubble shows at its top edge.
- Widget body text in the panes is ~20 px, under the 28 px floor; the numbers, titles and
  bubbles carry the read, and the pull-back shows the dashboards whole, not to be read.
