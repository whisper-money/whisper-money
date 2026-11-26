import { cn } from '@/lib/utils';
import {
    AnimatePresence,
    motion,
    useIsPresent,
    type Variants,
} from 'motion/react';
import { useCallback, useState, type ReactNode } from 'react';

/** 1 drills into a level (the new one comes from the right), -1 goes back. */
export type LevelDirection = 1 | -1;

const LEVEL_TRANSITION = { duration: 0.22, ease: 'easeOut' } as const;

/**
 * Both panels move at once: the new one slides in from the side it is
 * coming from while the current one leaves by the other.
 */
const SLIDE_VARIANTS: Variants = {
    enter: (direction: LevelDirection) => ({
        x: `${direction * 100}%`,
        opacity: 0,
    }),
    center: { x: 0, opacity: 1 },
    exit: (direction: LevelDirection) => ({
        x: `${direction * -100}%`,
        opacity: 0,
    }),
};

/** Under reduced motion the levels only crossfade, in place. */
const FADE_VARIANTS: Variants = {
    enter: { opacity: 0 },
    center: { opacity: 1 },
    exit: { opacity: 0 },
};

function prefersReducedMotion(): boolean {
    return (
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    );
}

/**
 * The drill-down levels of the user menu sheet. The levels are stacked in
 * one box whose height follows the level being shown, so the sheet grows or
 * shrinks with the content instead of jumping when the old level leaves.
 */
export function UserMenuSheetLevels({
    levelKey,
    direction,
    onEntered,
    children,
}: {
    levelKey: string;
    direction: LevelDirection;
    /** Called once the level has finished coming in: time to move focus. */
    onEntered: () => void;
    children: ReactNode;
}) {
    const [height, setHeight] = useState<number | null>(null);
    // Read on each change of level, so a setting changed while the sheet is
    // open applies to the next transition.
    const variants = prefersReducedMotion() ? FADE_VARIANTS : SLIDE_VARIANTS;

    return (
        <div
            className="relative min-h-0 shrink overflow-hidden transition-[height] duration-220 ease-out motion-reduce:transition-none"
            style={height === null ? undefined : { height }}
            data-testid="user-menu-sheet-levels"
        >
            <AnimatePresence initial={false} custom={direction}>
                <LevelPanel
                    key={levelKey}
                    direction={direction}
                    variants={variants}
                    onHeight={setHeight}
                    onEntered={onEntered}
                >
                    {children}
                </LevelPanel>
            </AnimatePresence>
        </div>
    );
}

function LevelPanel({
    direction,
    variants,
    onHeight,
    onEntered,
    children,
}: {
    direction: LevelDirection;
    variants: Variants;
    onHeight: (height: number) => void;
    onEntered: () => void;
    children: ReactNode;
}) {
    const isPresent = useIsPresent();

    // Only the level being shown sizes the box; the one leaving keeps quiet.
    const measure = useCallback(
        (element: HTMLDivElement | null) => {
            if (!element || !isPresent) {
                return undefined;
            }

            onHeight(element.offsetHeight);

            if (typeof ResizeObserver === 'undefined') {
                return undefined;
            }

            const observer = new ResizeObserver(() =>
                onHeight(element.offsetHeight),
            );
            observer.observe(element);

            return () => observer.disconnect();
        },
        [isPresent, onHeight],
    );

    return (
        <motion.div
            custom={direction}
            variants={variants}
            transition={LEVEL_TRANSITION}
            initial="enter"
            animate="center"
            exit="exit"
            onAnimationComplete={(definition) => {
                if (definition === 'center') {
                    onEntered();
                }
            }}
            // The level on its way out can be neither focused nor read.
            inert={!isPresent}
            aria-hidden={!isPresent || undefined}
            className={cn(
                'absolute inset-x-0 top-0 max-h-full overflow-y-auto',
                !isPresent && 'pointer-events-none',
            )}
        >
            <div ref={measure}>{children}</div>
        </motion.div>
    );
}
