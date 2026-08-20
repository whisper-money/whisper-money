import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import { Check, ChevronRight } from 'lucide-react';
import { type PropsWithChildren, type ReactNode } from 'react';

/** Hairline-divided list — the single content vocabulary of the onboarding. */
export function StepList({
    className,
    children,
}: PropsWithChildren<{ className?: string }>) {
    return (
        <div className={cn('flex flex-col divide-y border-t', className)}>
            {children}
        </div>
    );
}

/** Trailing marker for a row that leads somewhere. */
export function StepChevron() {
    return (
        <ChevronRight className="size-5 shrink-0 text-muted-foreground/45 transition-colors group-hover:text-muted-foreground" />
    );
}

/** Trailing marker for a row that is already done or selected. */
export function StepCheck({ muted = false }: { muted?: boolean }) {
    return (
        <Check
            className={cn('size-5 shrink-0', muted && 'text-muted-foreground')}
        />
    );
}

interface StepRowProps {
    icon?: LucideIcon;
    /** Replaces the icon: a step number, a bank logo, a checkbox. */
    leading?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    /** A condition worth reading before acting, e.g. a plan price. */
    meta?: ReactNode;
    /** Small pill next to the title. */
    badge?: ReactNode;
    /** Right-hand marker: a {@link StepChevron}, a {@link StepCheck}, a badge. */
    trailing?: ReactNode;
    /**
     * `compact` steps the row down to the app's own density, for the rows this
     * vocabulary lends to pages inside the normal app chrome (settings →
     * billing), where onboarding-tall rows read as a flow leaking in.
     */
    size?: 'default' | 'compact';
    onClick?: () => void;
}

export function StepRow({
    icon: Icon,
    leading,
    title,
    description,
    meta,
    badge,
    trailing,
    size = 'default',
    onClick,
}: StepRowProps) {
    const compact = size === 'compact';

    const content = (
        <>
            {leading ??
                (Icon ? (
                    <Icon
                        className={cn(
                            'shrink-0 text-muted-foreground',
                            compact ? 'size-4' : 'size-5',
                        )}
                    />
                ) : null)}

            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span
                    className={cn(
                        'flex flex-wrap items-center gap-2 leading-tight font-medium',
                        compact ? 'text-sm' : 'text-base',
                    )}
                >
                    {/* The title keeps its own element so an exact-text lookup
                        (Pest's click/assertSee) resolves to the title alone
                        rather than to "title + badge". */}
                    <span>{title}</span>
                    {badge}
                </span>
                {description && (
                    <span
                        className={cn(
                            'leading-snug text-pretty text-muted-foreground',
                            compact ? 'text-[13px]' : 'text-sm',
                        )}
                    >
                        {description}
                    </span>
                )}
                {meta && (
                    <span className="mt-0.5 text-[13px] leading-snug font-medium text-pretty">
                        {meta}
                    </span>
                )}
            </span>

            {trailing}
        </>
    );

    const shared = cn(
        'flex w-full items-center text-left',
        compact ? 'min-h-9 gap-3 py-3' : 'min-h-11 gap-3.5 py-4',
    );

    if (!onClick) {
        return <div className={shared}>{content}</div>;
    }

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                shared,
                'group cursor-pointer rounded-sm outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
            )}
        >
            {content}
        </button>
    );
}

/** Neutral pill used for account capability labels and plan names. */
export function StepBadge({ children }: PropsWithChildren) {
    return (
        <span className="shrink-0 rounded-full border px-2.5 py-1 text-xs leading-none font-medium text-muted-foreground">
            {children}
        </span>
    );
}

/**
 * The label above a {@link StepList} that needs naming — a bank a group of
 * accounts belongs to, or an alternative to the main action on the screen.
 */
export function StepSectionLabel({ children }: PropsWithChildren) {
    return (
        <div className="flex items-center gap-2.5 pt-4 pb-2.5 text-xs font-medium tracking-wider text-muted-foreground uppercase">
            {children}
        </div>
    );
}

/** The numbered marker used by the "export from your bank" instructions. */
export function StepNumber({ children }: PropsWithChildren) {
    return (
        <span className="flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium text-muted-foreground">
            {children}
        </span>
    );
}
