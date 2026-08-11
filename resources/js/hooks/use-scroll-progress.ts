import { useEffect, useRef, useState } from 'react';

/**
 * How far an element has travelled through the viewport: 0 before it enters from
 * the bottom, 1 once it has left through the top. Drives the scroll-linked
 * animations in the landing page previews.
 */
export function useScrollProgress<T extends HTMLElement>() {
    const [progress, setProgress] = useState(0);
    const ref = useRef<T | null>(null);

    useEffect(() => {
        const updateProgress = () => {
            const element = ref.current;
            if (!element) {
                return;
            }

            const rect = element.getBoundingClientRect();
            const viewportHeight = window.innerHeight || 1;
            const next =
                (viewportHeight - rect.top) / (viewportHeight + rect.height);
            setProgress(Math.min(1, Math.max(0, next)));
        };

        updateProgress();
        window.addEventListener('scroll', updateProgress, { passive: true });
        window.addEventListener('resize', updateProgress);

        return () => {
            window.removeEventListener('scroll', updateProgress);
            window.removeEventListener('resize', updateProgress);
        };
    }, []);

    return { ref, progress };
}

interface UseScrollTranslateOptions {
    /** Fraction of the scrollable distance the list moves per unit of progress. */
    speed: number;
    /** Extra travel past the overflow so the last row clears the container. */
    padding: number;
}

/**
 * A list that scrolls itself as the page scrolls past it: returns the refs to
 * wire up plus how far the list should be translated.
 */
export function useScrollTranslate<
    C extends HTMLElement,
    L extends HTMLElement,
>({ speed, padding }: UseScrollTranslateOptions) {
    const { ref: containerRef, progress } = useScrollProgress<C>();
    const listRef = useRef<L | null>(null);
    const [maxTranslate, setMaxTranslate] = useState(0);

    useEffect(() => {
        const updateMaxTranslate = () => {
            const container = containerRef.current;
            const list = listRef.current;
            if (!container || !list) {
                return;
            }

            setMaxTranslate(
                Math.max(
                    0,
                    list.scrollHeight - container.clientHeight + padding,
                ),
            );
        };

        updateMaxTranslate();
        window.addEventListener('resize', updateMaxTranslate);

        return () => {
            window.removeEventListener('resize', updateMaxTranslate);
        };
    }, [containerRef, padding]);

    return {
        containerRef,
        listRef,
        progress,
        translateY: progress * maxTranslate * speed,
    };
}
