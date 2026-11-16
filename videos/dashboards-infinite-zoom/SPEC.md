# SPEC: Infinite zoom / «Zoom infinito»

```text
GOAL:    the viewer leaves knowing no widget was waiting in a template: each one
         was written by their own agent from one sentence, for their case.
OUTPUT:  HyperFrames, 1920 x 1080, 30 fps, one composition, EN and ES renders
         from the same file (copy and captures are root variables).
TIME:    32.5 s x 30 = 975 frames. Pulse 92 BPM (19.6 f/beat) in cycle 1,
         112 BPM (16.1 f) in cycle 2, 132 BPM (13.6 f) in cycle 3, then none.
LOOK:    flat colour fields, no texture, no gradient, one per cycle, each the
         colour of the mark the camera dove into, sampled from the captures:
         hook #e4e4e7 (zinc-200) · cycle 1 #00bc7d (emerald bar) ·
         cycle 2 #fe9a00 (amber bar) · cycle 3 #d5e5ff (car loan area fill) ·
         claim #1f1f1f (the app's dark --background) · end card #f4f4f5.
         Ink #0a0a0a on light fields, #fafafa on #1f1f1f. One accent, amber
         #fe9a00, on one word of the claim only.
         Type: IBM Plex Sans (the app's sans) 600, asks 60 px, claim 112 px at
         -2% tracking; Google Sans Code (the app's mono) 28 px for code.
         Medium: the app's real light-mode UI, captured at 3x in headless
         Chrome; widgets drawn 1500 px wide (78% of the frame), the dashboard
         at 88-100% of the frame. Cards: 24 px radius, shadow 0 24px 60px
         rgba(0,0,0,0.18). No glow, no grain, no vignette.
OBJECTS: the real widgets (weekly shop, takeaway, car loan, kids' activities)
         and the real "Home" dashboard of the local promo account; a neutral
         chat bubble (white, 28 px radius, person's initial in a 64 px circle,
         their name above); the agent card (#1f1f1f, "Your agent / Tu agente",
         the widget's real source lines); the app's own logo file.
MOTION:  dive: scale 1 -> 30 on the bar's centre, power3.in, 1.2 s first,
         0.85 s second, 0.8 s third (the zoom speeds up with the pulse);
         arrivals expo.out 0.5 s; bubble grows from the dive point, scale
         0.4 -> 1, elastic damping ~0.75 (back.out(1.2)); words blur 6 -> 0 px,
         0.067 s apart; code lines wipe in left -> right 0.16 s each;
         widget lands with an inset clip rising from the bottom, 0.5 s
         expo.out, from scale 0.96; pull-back power2.inOut 1.5 s; exits 40%
         shorter than entrances; landed text is dead still.
SHOTS:   0.0-1.6   hook: real "weekly shop" widget on #e4e4e7, frame 0 is the
                   thumbnail; at 0.4 the camera dives into the tallest bar;
                   whoosh peaks at 1.6; the bar's green becomes the field.
         1.6-9.0   cycle 1 on emerald: Maya's ask grows out of the dive point
                   (read 1.75-3.9); it rises, the agent card opens under it,
                   8 real source lines type (4.4-6.0); the card becomes the
                   takeaway widget (6.3), "Checked, then placed on your
                   dashboard" (6.9); hold; dive into the Friday bar 8.1-9.0.
         9.0-15.0  cycle 2 on amber: Tom's ask about the car (read 9.1-10.8),
                   code 11.2-12.4, the car loan widget lands 12.6, dive into
                   the paid-so-far area 14.2-15.0.
         15.0-19.8 cycle 3 on pale blue: Priya's ask about the kids (read
                   15.1-16.7), code 17.0-18.0, the kids widget lands 18.2.
         19.8-23.6 pull back: the kids widget is the same pixels as its cell
                   in the dashboard capture; the camera pulls back until the
                   whole "Home" dashboard fills 88% of the frame; pulse stops,
                   pad and a low impact release; hold 2.2 s.
         23.6-27.8 claim on #1f1f1f: "No widget existed / until you asked for
                   it." per word, held 2.3 s; swaps to "Built by your agent,
                   for you." held 1.6 s.
         27.8-32.5 end card on #f4f4f5: the logo fills up from the bottom,
                   "Whisper Money" wipes in, tagline and the whisper.money
                   button land at 29.4, the plan note under it; held 3 s.
BANNED:  a dark void as the ground with UI floating in it; glow, bloom, neon,
         particles; purple/blue gradients, glass cards; cream paper with a
         serif; everything centred AND fading up at once; text moving while
         read; corner labels, HUD; three cards in a row; a title over a dimmed
         screenshot; the logo docked in a corner; invented UI (every widget,
         number and label is a capture of the real app); a copy of Claude's or
         ChatGPT's chat UI; pill-shaped chips; synthesised tones.
QA:      0.0 (thumbnail: a whole real widget, not black) · 1.5 (frame filled
         by the bar's green) · 3.0 (ask 1 readable) · 5.5 (code readable,
         >= 28 px) · 7.5 (takeaway widget >= 40% of the frame) · 13.5 (car
         widget) · 19.0 (kids widget) · 22.5 (whole dashboard) · 25.5 (claim)
         · 31.5 (end card: logo, name, URL, CTA). Phone width: asks and claim
         readable at 360 px.
```

## Copy

All copy lives in `index.html`'s `data-composition-variables` (English
defaults) and `variables.es.json` (Spanish). Lines trace to the landing copy
(`resources/js/components/landing/custom-dashboards-section.tsx`, `lang/es.json`)
or to the real widgets:

| id      | EN                                                                   | ES                                                                  |
| ------- | -------------------------------------------------------------------- | ------------------------------------------------------------------- |
| claim   | No widget existed until you asked for it.                            | Ningún widget existía antes de que lo pidieras.                     |
| claim_2 | Built by your agent, for you.                                        | Lo construye tu agente, para ti.                                    |
| tagline | Ask for a chart. It lands on your dashboard.                         | Pide un gráfico. Aparece en tu dashboard.                           |
| note    | On the paid plan, with Claude or ChatGPT connected to Whisper Money. | En el plan de pago, con Claude o ChatGPT conectado a Whisper Money. |

The asks are illustrative; the numbers on screen are the local promo users'
seeded data.
