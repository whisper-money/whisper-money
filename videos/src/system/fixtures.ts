/**
 * Helpers for the fake data videos show. Every figure in a video is made up
 * here or next to the video: never load real user data into one.
 */

/** Whole currency units to the minor units (cents) the app stores. */
export function money(units: number): number {
    return Math.round(units * 100);
}

/** Minor units stay whole; rates and other fractions scale as they are. */
function scale(field: number, progress: number): number {
    return Number.isInteger(field)
        ? Math.round(field * progress)
        : field * progress;
}

/**
 * The same object with every number scaled by `progress` (0 → 1), so a real
 * component fed it counts its figures up as the value grows.
 */
export function countUp<T extends object>(value: T, progress: number): T {
    return Object.fromEntries(
        Object.entries(value).map(([key, field]) => [
            key,
            typeof field === 'number' ? scale(field, progress) : field,
        ]),
    ) as T;
}
