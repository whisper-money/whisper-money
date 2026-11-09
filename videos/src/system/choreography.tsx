import { useLayoutEffect, useRef, type ReactNode } from 'react';
import { spring, useCurrentFrame, useVideoConfig } from 'remotion';

/** How an element comes in: lifting up, wiping in from the left, or popping. */
export type RevealEffect = 'rise' | 'wipe' | 'pop';

/** Elements inside the choreography that come in together, a few frames apart. */
export interface RevealStep {
    /** CSS selector, matched inside the choreography. */
    selector: string;
    /** Scene frame the first match starts at. */
    at: number;
    effect?: RevealEffect;
    /** Frames between one match and the next. */
    stagger?: number;
}

function styleFor(
    effect: RevealEffect,
    progress: number,
): Partial<CSSStyleDeclaration> {
    const hidden = 1 - progress;

    switch (effect) {
        case 'wipe':
            return { clipPath: `inset(0 ${hidden * 100}% 0 0)` };
        case 'pop':
            return {
                opacity: String(Math.min(1, progress * 2)),
                transform: `scale(${0.6 + progress * 0.4})`,
            };
        case 'rise':
            return {
                opacity: String(progress),
                transform: `translateY(${hidden * 24}px)`,
            };
    }
}

/**
 * Reveals the inner parts of a real component one after another. App
 * components can't be edited for a video, so steps reach into them by CSS
 * selector and style the matches on every frame; a later step wins over an
 * earlier one on the same element.
 */
export function Choreography({
    steps,
    children,
}: {
    steps: RevealStep[];
    children: ReactNode;
}) {
    const container = useRef<HTMLDivElement>(null);
    const frame = useCurrentFrame();
    const { fps } = useVideoConfig();

    useLayoutEffect(() => {
        const root = container.current;

        if (!root) {
            return;
        }

        const apply = () =>
            steps.forEach(({ selector, at, effect = 'rise', stagger = 5 }) => {
                root.querySelectorAll<HTMLElement>(selector).forEach(
                    (element, index) => {
                        const progress = spring({
                            frame: frame - at - index * stagger,
                            fps,
                            config:
                                effect === 'pop'
                                    ? { damping: 12 }
                                    : { damping: 200 },
                        });

                        Object.assign(
                            element.style,
                            styleFor(effect, progress),
                        );
                    },
                );
            });

        apply();
        // Charts mount their parts a render later (once measured), after this
        // effect: style those too when they appear.
        const observer = new MutationObserver(apply);
        observer.observe(root, { childList: true, subtree: true });

        return () => observer.disconnect();
    });

    return <div ref={container}>{children}</div>;
}
