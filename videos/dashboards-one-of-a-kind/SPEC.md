# SPEC — One of a kind

```text
GOAL:    the viewer leaves knowing that on Whisper Money no widget is pre-made: you ask
         your own AI agent a question about your life and it writes the widget for you.
OUTPUT:  HyperFrames, 1920 x 1080, 30 fps. One composition, two renders (EN, ES) through
         the variables in index.html (variables/es.json holds the Spanish values).
TIME:    31.5 s x 30 = 945 frames. 96 BPM, 18.75 frames a beat, a bar every 2.5 s (75 frames).
         Widgets land on beats 8, 16, 20 and 24 (5.0, 10.0, 12.5 s) and just after beat 24 (15.3 s).
LOOK:    ground: the app's own light UI, captured from the real app at 1280 x 1010 CSS px,
         device scale 3, drawn at 1.5x (1 page px = 1.5 frame px). Off-page ground #fafafa
         (the app's sidebar). End card ground #1f1f1f (the brand's dark background).
         Ink #1b1b18 (the app's foreground), muted #706f6c.
         Accent: green #16a34a on light (3.3:1 on #fafafa), #4ade80 on dark. Allowed only on the widget being
         born (a 2 px ring for 1.2 s), the dashboard's name once it is "Just mine", one word
         of the claim and the URL.
         Type: IBM Plex Sans 400/600 (the app's own font, shipped in assets/fonts); code in
         Google Sans Code (shipped); wordmark in IBM Plex Mono 600 (bundled).
         Claim 84 px / -2% tracking, 600. Chat text 20 page px (30 px at rest, 48 px zoomed).
         Medium: product UI. Shadows only on the page card in the claim shot:
         0 24px 60px rgba(0,0,0,0.12). No grain, no vignette.
OBJECTS: the dashboard (real chrome + real widget screenshots, positions measured from the
         real grid); the empty slot (1.5 px dashed #d4d4d0 rounded 12 px, the size of a real
         1/2 cell); the person's message (the landing's sent bubble: #27272a, white text,
         radius 14 14 4 14); "Your agent" (a neutral label with a small grey dot, never a
         Claude or ChatGPT look); tool chips with the real MCP tool names (get_widget_sdk,
         create_widget, place_widget) in the landing's chip style; the code panel showing the
         widget's real saved source; the bird (public/favicon/apple-touch-icon.svg, its own
         paths, drawn on by their strokes).
MOTION:  arrivals expo.out / power4.out; camera power2.inOut 0.6 s; exits power3.in, 40-50%
         shorter than entries. Chat messages enter as the registry's chat-message does:
         scale 0.75 -> 1 from the sender's corner in 0.12 s. Words of a sentence 0.07 s apart,
         blur 6 -> 0. A widget lands: blur 8 -> 0, scale 0.96 -> 1 in 0.35 s expo.out.
         Reshuffle: every widget moves to its new cell in 0.7 s power3.inOut, 60 ms stagger.
SHOTS:
  0.0-1.9   whole dashboard, groceries + empty slot; the dog question types into the slot
            per word (read 0.1-1.9). Sound: bed starts.
  1.9-2.5   camera pushes to the slot (scale 2.4, 0.6 s).
  2.5-5.0   "Your agent": get_widget_sdk ✓, create_widget, the code panel opens and the real
            TSX scrolls for 1.5 s. Sound: soft typing.
  5.0-6.25  the panel becomes the widget: it lands in the slot, live; place_widget ✓; green
            ring. Sound: click. Camera pulls back (5.6-6.2).
  6.25-10.0 a second slot opens under groceries; "How many coffees before 10am?"; chip + code
            flash; lands at 10.0. Click.
  10.0-12.5 third slot; "What's left for Japan?"; lands at 12.5. Click.
  12.5-15.6 camera pans down a row (12.8-13.4); fourth slot; "Fuel or bike: which costs
            more?"; lands at 15.0. Click.
  15.6-18.75 the grid reshuffles into the final layout (three thirds over two halves, the
            positions of the real saved dashboard) while the camera pans back up; the
            dashboard's name "Just mine" gets the accent underline. Sound: whoosh.
  18.75-23.9 the page shrinks into a card on the right; the claim builds per word on the
            left in three lines, "asked" in green. Holds 3 s after the last word.
  23.9-31.5 colour flood to #1f1f1f; the white tile fills from the bottom, the bird draws on,
            the wordmark wipes in; "Ask for a chart. It lands on your dashboard."; the URL;
            the plan line. Holds 3.5 s. Sound: riser into the tile, whoosh on the flood.
BANNED:  the defaults in spec.md (dark void with floating UI, glow, gradients, glass cards,
         cream paper + serif, everything centred, fade-and-slide-up on everything, moving
         titles, corner labels and HUD, three cards in a row, title over a dimmed screenshot,
         docked logo); typing into an empty prompt box as the opener; any invented widget,
         number or button; any Claude or ChatGPT interface look; tilted 3D UI.
QA:      0.0 (thumbnail: dashboard + slot + first words), 1.8 (question readable), 3.8 (code
         visible), 5.4 (dog widget landed, ring), 9.0 (coffee ask), 15.2 (fuel landed, row 3),
         17.4 (final layout matches the saved dashboard in the app), 22.0 (claim), 29.0 (end card), 31.4.
```
