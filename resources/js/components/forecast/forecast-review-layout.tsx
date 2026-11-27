import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { type ReactNode } from 'react';

interface ReviewStepLayoutProps {
    eyebrow: string;
    title: string;
    description: string;
    children: ReactNode;
    footer: ReactNode;
}

/**
 * The frame both steps of the review share: a header with the step and the
 * question, a body that scrolls, and a footer with the actions. It fills the
 * dialog on a computer and the whole screen on a phone.
 */
export function ReviewStepLayout({
    eyebrow,
    title,
    description,
    children,
    footer,
}: ReviewStepLayoutProps) {
    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex flex-col gap-1.5 border-b px-6 pt-6 pb-4">
                <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {eyebrow}
                </span>
                <DialogTitle className="text-xl leading-tight font-semibold">
                    {title}
                </DialogTitle>
                <DialogDescription className="text-sm text-muted-foreground">
                    {description}
                </DialogDescription>
            </div>
            <div className="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-6 pt-2 pb-4">
                {children}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t px-6 py-4">
                {footer}
            </div>
        </div>
    );
}

/** A section title inside a step: "INCOME", "EXPENSES"… */
export function ReviewSectionTitle({ children }: { children: ReactNode }) {
    return (
        <h3 className="mt-4 mb-1 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
            {children}
        </h3>
    );
}
