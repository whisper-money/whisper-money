# Landing product video

Remotion project for the product video on the landing page.

- `bun run studio`: preview in Remotion Studio.
- `bun run voice`: regenerates the voice-over clips (`public/voice/<locale>/`) and their timings (`src/voice-timings.json`) with ElevenLabs. Needs `ELEVENLABS_API_KEY` in the environment; unchanged lines are skipped.
- `bun run render:all`: renders every locale × theme, with posters, into `../public/images/landing_videos/`.

Each locale's scene lengths come from its voice-over, and `src/timeline.ts` stretches them so the music build lands on the Turn scene and the lift on the Comparison scene. Those 24 seconds of music cap how long the lines from Turn to Privacy can be: the render fails if they no longer fit.

When the video length changes, update the landing labels too ("See it in 46 seconds" and "Tap to watch · 0:46" in `resources/js/components/landing/hero-video.tsx`, `lang/es.json` and `lang/fr.json`).

## Credits

Music: "The Light" by Alex Jones / Xander Jones (`public/music/the-light.mp3` is the excerpt the video uses).
