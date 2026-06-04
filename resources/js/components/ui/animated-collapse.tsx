import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

interface AnimatedCollapseProps {
    open: boolean;
    children: ReactNode;
    className?: string;
}

/**
 * Animates its content open/closed by transitioning the grid row track from
 * `0fr` to `1fr`, which lets an auto-height element grow and shrink smoothly
 * without measuring it in JavaScript.
 */
export function AnimatedCollapse({
    open,
    children,
    className,
}: AnimatedCollapseProps) {
    return (
        <div
            aria-hidden={!open}
            className={cn(
                'grid transition-[grid-template-rows] duration-200 ease-out motion-reduce:transition-none',
                open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]',
            )}
        >
            <div className={cn('min-h-0 overflow-hidden', className)}>
                {children}
            </div>
        </div>
    );
}
