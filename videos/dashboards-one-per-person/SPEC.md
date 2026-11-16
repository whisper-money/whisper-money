# SPEC — One dashboard per person

GOAL: the viewer sees three strangers get three dashboards nothing alike from one
sentence each, and wonders what theirs would be.
OUTPUT: HyperFrames, 1920 x 1080, 30 fps. Two renders from one composition: `lang` en / es.
TIME: 33.75 s x 30 = 1013 frames. Music 96 BPM: 18.75 frames a beat, a bar every 2.5 s.

LOOK:

- Grounds, one per person, from the app's colorful chart palette (Tailwind 300 tones):
  freelancer amber #FCD34D, family emerald #6EE7B7, traveller blue #93C5FD. Flat, no texture.
  The merge and end card sit on the app's own dark background #1F1F1F.
- Ink #1F1F1F on the grounds, #FAFAFA on dark. One accent: emerald #34D399, only on the URL.
- Type: the app's own faces from resources/fonts. IBM Plex Sans 700 for names (52 px,
  -2% tracking), 500 for bubbles (30 px) and context (28 px); the question 120 px 700;
  Google Sans Code 600 for the wordmark (56 px).
- Medium: real product UI. Every widget is a headless capture of the real app (phone
  layout, 430 css px at 3x), shown at 560 px wide (1.47x its css size).
- Bubbles: the person's is white with dark ink, radius 28 with an 8 px sender corner;
  the agent's is #1F1F1F with light ink, labelled "Your agent". Neutral, no product chrome.
- Depth: widgets carry a 0 18px 40px rgba(0,0,0,.28) shadow on the ground. No glow, no grain.

OBJECTS: a pane is a person's phone-width dashboard; a widget is the real card the app
draws; the empty dashboard is the app's real empty state; the logo is the brand's bird
(public/favicon/favicon.svg) on its white tile.

MOTION:

- Enter: expo.out 0.5 s with 8 px -> 0 blur; bubbles scale 0.75 -> 1 from the sender's
  corner in 0.3 s. Moves and scrolls: power2.inOut 0.6 s. Exits and push-throughs: power3.in.
- Widgets land on beats, staggered 0.625 s across panes so all three build at once.
- Exits 40-50% shorter than entrances. Nothing loops.

SHOTS:

1. 0.00-2.50 Three panes, names and context, an empty dashed dashboard in each (frame 0
   is the thumbnail). Pad layer plays.
2. 2.50-10.00 One sentence per person on the bar: 2.5 / 5.0 / 7.5. Arpeggio enters at 5.0,
   rhythm at 7.5. Pop on each bubble.
3. 10.00-18.75 The three agents answer at once, then nine real widgets land, one beat
   apart across panes; each pane scrolls like a phone as its dashboard grows.
4. 18.75-21.25 Pull back: each pane's stack zooms out to show its whole dashboard. Three
   dashboards side by side, nothing alike. Whoosh.
5. 21.25-26.25 Merge: the outer panes slide into the centre as the real empty dashboard
   opens from the middle pane to full frame. "What about yours?" lands word by word.
6. 26.25-33.75 Push into the empty state's grid icon, then the end card: the bird tile fills
   with the three grounds and white, the wordmark wipes in, tagline, whisper.money, the plan
   line. Riser into impact on the mark. All three layers fade together at the end.

BANNED: the spec defaults (dark void with floating elements, glow, gradients, glass,
cream paper + serif, everything centred, everything fading up, drifting titles, corner
labels, three cards in a row, KPI grids, title over dimmed screenshot, logo in a corner);
a copy of Claude's or ChatGPT's chat UI; invented UI or numbers; typing dots that loop.

QA:

- 0.0 three named panes, no black frame.
- 8.5 three bubbles readable at once.
- 18.0 every pane holds real widgets, no clipped text at the pane edge.
- 20.5 three whole dashboards side by side.
- 24.0 the empty dashboard full frame with the question.
- 31.0 logo lockup, tagline, URL; ES render shows Spanish copy and Spanish widgets.
