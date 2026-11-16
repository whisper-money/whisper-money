# REVIEW — One of a kind

Delivery note for the next turn. Read it before changing anything.

## What was built

Storyline A from `STORYLINES.md`, picked by the orchestrator: one real Whisper Money
dashboard ("Just mine" / «Lo mío») that rebuilds itself as a person asks four questions
about their own life. 31.5 s, 1920 x 1080, 30 fps, 96 BPM. One composition renders
both languages: `npx hyperframes render --variables-file variables/<en|es>.json`.

## Where the product comes from

- Local users `promo-a-en@whisper.local` (USD, en) and `promo-a-es@whisper.local`
  (EUR, es) on the Standard plan (a seeded `sub_e2e_` subscription) with the
  CustomDashboards flag on and about a year of transactions: a Dog / Perro label,
  coffee with the card terminal's time at the end of the description, a one-off
  "Japan trip" / «Viaje a Japón» goal fed by a Japan label, fuel falling after a bike
  bought in February. The seeding script was not committed (out of scope).
- The five widgets were written as the agent and saved through
  `WidgetDefinitionService::create()` and `place()`, the path `create_widget` and
  `place_widget` take, so the manifest was validated and the TSX compiled. The
  validator refused one draft (`progress.target`); it now reads it with `get()`.
- Every widget and the page chrome are screenshots of the running app
  (Playwright, headless Chrome, 1280 x 1010 CSS px at device scale 3). Positions in
  `index.html` are the measured grid cells. The final layout is a real saved layout
  (three 1/3 over two 1/2). The code on screen is each widget's saved source.
- The bird is `public/favicon/apple-touch-icon.svg`, drawn on by its own paths.
  The copy on the end card is the landing's (`custom-dashboards-section.tsx`,
  `lang/es.json`). Amounts are the local test users' (illustrative).

## Creative calls

- The empty slot is a dashed outline the size of a real 1/2 cell. The app does not
  draw one; it marks where the next widget will land.
- The agent is a neutral label ("Your agent" / «Tu agente») with the real MCP tool
  names as chips; the person's message uses the landing's sent bubble.
- The accent green is rationed: the ring on each widget as it lands, the dashboard
  picker once it is "Just mine", one word of the claim (green-600 on light for 3:1
  contrast) and the URL.
- The cut to the end card is an iris of the brand's dark ground growing from the
  claim's green word, not a crossfade.
- Sound: the bed is the repo's own synthesized loop (`videos/scripts/generate-music.mjs`)
  re-run at 96 BPM with a soft tick and kick, rendered offline; clicks, key ticks,
  whoosh and riser are HyperFrames' bundled SFX. No voiceover.

## Measured

- `npx hyperframes check --json`: ok, no errors.
- `snapshot --review` and frame sheets of both renders read in order; the ES render
  shows the app, widgets and copy in Spanish.
- Loudness of both renders: -16.0 LUFS integrated, -1.5 dBFS peak. The sound was
  measured, not heard.

## Warnings kept on purpose

- `line_unread` on the code panels and the `place_widget ✓` chip: the code is shown
  "for a moment" as proof the agent writes it, not to be read. The fuel question
  holds 2 s, just under its 2.2 s floor, because the beat lands on the bar.
- `text_below_floor`: the chips and the code (18 px at rest, 29 px in the close-up).
  The sentence that must be read (the bubble) is 30-48 px.
- `nested_structure_needs_subcomposition` / `composition_file_too_large`: one root
  composition on purpose. `--variables` only reaches the top-level composition, and
  that is what lets one file render EN and ES.

## Weakest shot and what to change with more time

The reshuffle (16.25-17 s): the half-width captures of dog, coffee and Japan
crossfade into their third-width captures while they move, so the middle frames are
a blur swap rather than a true resize. A real drag-and-resize recording of the app
would be better. The claim card on the right is also small (0.7 of the page); a
tighter crop of the new layout would carry more.

## Code and product reviews

- Applied: the plan line ("On the paid plan, with Claude or ChatGPT connected to
  Whisper Money.") is larger and brighter and lands with the call to action, so the
  chat in the slot doesn't read as an in-app feature; `window.__timelines` is
  initialised before registering; `render:en` / `render:es` scripts in `package.json`.
- Kept: the coffee code panel is the widget's real saved source (it reads the hour
  the card terminal printed in the description); "dashboard" in the Spanish CTA is
  the landing's own copy (`lang/es.json`).
- `variables/en.json` mirrors the defaults declared on `index.html`; change both.

## Not built

Storylines B ("Infinite zoom") and C ("One dashboard per person") are separate
projects owned by other agents.
