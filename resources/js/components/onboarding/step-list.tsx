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
    trailing?: 'chevron' | 'check' | ReactNode;
    onClick?: () => void;
    'data-testid'?: string;
}

export function StepRow({
    icon: Icon,
    leading,
    title,
    description,
    meta,
    badge,
    trailing,
    onClick,
    'data-testid': testId,
}: StepRowProps) {
    const trailingNode =
        trailing === 'chevron' ? (
            <ChevronRight className="size-5 shrink-0 text-muted-foreground/45 transition-colors group-hover:text-muted-foreground" />
        ) : trailing === 'check' ? (
            <Check className="size-5 shrink-0" />
        ) : (
            trailing
        );

    const content = (
        <>
            {leading ??
                (Icon ? (
                    <Icon className="size-5 shrink-0 text-muted-foreground" />
                ) : null)}

            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="flex flex-wrap items-center gap-2 text-base leading-tight font-medium">
                    {/* The title keeps its own element so an exact-text lookup
                        (Pest's click/assertSee) resolves to the title alone
                        rather than to "title + badge". */}
                    <span>{title}</span>
                    {badge}
                </span>
                {description && (
                    <span className="text-sm leading-snug text-pretty text-muted-foreground">
                        {description}
                    </span>
                )}
                {meta && (
                    <span className="mt-0.5 text-[13px] leading-snug font-medium text-pretty">
                        {meta}
                    </span>
                )}
            </span>

            {trailingNode}
        </>
    );

    const shared = 'flex min-h-11 w-full items-center gap-3.5 py-4 text-left';

    if (!onClick) {
        return <div className={shared}>{content}</div>;
    }

    return (
        <button
            type="button"
            onClick={onClick}
            data-testid={testId}
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

/** The numbered marker used by the "export from your bank" instructions. */
export function StepNumber({ children }: PropsWithChildren) {
    return (
        <span className="flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-medium text-muted-foreground">
            {children}
        </span>
    );
}
