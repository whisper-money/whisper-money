import { useCallback, useState } from 'react';

/**
 * The border-box width of an element, kept live. For layouts that answer to the room
 * they are given rather than to the viewport, e.g. a list beside the sidebar.
 *
 * Null until the element mounts: the first measurement happens in the commit,
 * so the browser never paints a frame laid out for an unknown width.
 */
export function useElementWidth<T extends Element>(): [
    (element: T | null) => (() => void) | undefined,
    number | null,
] {
    const [width, setWidth] = useState<number | null>(null);

    const ref = useCallback((element: T | null) => {
        if (!element) {
            return undefined;
        }

        setWidth(element.getBoundingClientRect().width);

        if (typeof ResizeObserver === 'undefined') {
            return undefined;
        }

        // The border box both times, so the first width and later ones agree.
        const observer = new ResizeObserver(() =>
            setWidth(element.getBoundingClientRect().width),
        );
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    return [ref, width];
}
