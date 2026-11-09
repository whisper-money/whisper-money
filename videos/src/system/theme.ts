/**
 * Format and brand values shared by every feature video. Colors on the app's
 * own surfaces come from its Tailwind tokens (bg-card, text-foreground...), so
 * only the accents a video adds on top of them live here.
 */
export const VIDEO = {
    width: 1920,
    height: 1080,
    fps: 30,
} as const;

export const BRAND = {
    /** The app's dark --background (oklch(0.225 0 0)). */
    background: '#1f1f1f',
    url: 'whisper.money',
} as const;

/** Frames for a duration in seconds, at the video frame rate. */
export function seconds(value: number): number {
    return Math.round(value * VIDEO.fps);
}
