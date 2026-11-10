import { useMediaQuery } from '@/hooks/use-media-query';

const MOBILE_BREAKPOINT = 768;
const MOBILE_QUERY = `(max-width: ${MOBILE_BREAKPOINT - 1}px)`;

export function useIsMobile() {
    return useMediaQuery(MOBILE_QUERY);
}

/**
 * The same question answered right now, for a decision taken once in an
 * effect: {@link useIsMobile} reads `false` through the hydration render, so
 * an effect that runs off it then would take every phone for a computer.
 */
export function isMobileViewport(): boolean {
    return window.matchMedia(MOBILE_QUERY).matches;
}
