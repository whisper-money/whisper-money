import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

interface MarqueeProps {
    children: ReactNode;
    /** Sets `--duration` and `--gap` (both required by `.animate-marquee`), plus any layout or mask styles. */
    className?: string;
    /** Classes for each of the two scrolling copies (animation delay, pause on hover, reduced motion…). */
    trackClassName?: string;
}

/**
 * Infinite horizontal marquee: renders its children twice side by side and
 * scrolls both copies with `.animate-marquee` so the loop has no seam. The
 * second copy is hidden from assistive technology, which reads the content once.
 */
export function Marquee({ children, className, trackClassName }: MarqueeProps) {
    return (
        <div
            className={cn(
                'flex flex-row [gap:var(--gap)] overflow-hidden',
                className,
            )}
        >
            {[0, 1].map((copy) => (
                <div
                    key={copy}
                    aria-hidden={copy === 1 || undefined}
                    data-marquee-copy={copy}
                    className={cn(
                        'animate-marquee flex shrink-0 flex-row [gap:var(--gap)]',
                        trackClassName,
                    )}
                >
                    {children}
                </div>
            ))}
        </div>
    );
}
