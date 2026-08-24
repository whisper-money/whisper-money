import { cn } from '@/lib/utils';
import { AlertCircle } from 'lucide-react';
import { type PropsWithChildren, type ReactNode } from 'react';

interface StepScreenProps {
    title?: ReactNode;
    description?: ReactNode;
    /** Rendered in the action area: pinned to the bottom on phones, inline on desktop. */
    footer?: ReactNode;
    /** Vertically centre the content — for states with nothing to read but a spinner. */
    align?: 'top' | 'center';
    /** The categorizer needs a wider column than a form does. */
    width?: 'md' | 'xl';
    /**
     * Keep the action pinned on desktop too. The onboarding steps are short
     * enough that an inline footer sits inside the viewport; a screen whose
     * content is taller than the window needs its action reachable without
     * scrolling for it.
     */
    pinFooter?: boolean;
}

/**
 * Every onboarding step is the same screen: a large left-aligned title, a
 * supporting line, the content, and the action pinned within thumb reach.
 */
export function StepScreen({
    title,
    description,
    footer,
    align = 'top',
    width = 'md',
    pinFooter = false,
    children,
}: PropsWithChildren<StepScreenProps>) {
    return (
        <main className="flex flex-1 flex-col px-5 md:items-center md:px-7">
            <div
                className={cn(
                    'flex w-full flex-1 flex-col',
                    pinFooter ? 'md:pt-10 md:pb-20' : 'md:py-20',
                    // Hug the content on desktop so the actions sit under it,
                    // except when the state is meant to be centred in the page.
                    align === 'center' ? 'md:flex-1' : 'md:flex-none',
                    width === 'md' ? 'md:max-w-md' : 'md:max-w-xl',
                )}
            >
                <div
                    className={cn(
                        'flex flex-1 flex-col gap-7 pt-3 md:pt-0',
                        align === 'center' ? 'justify-center' : 'md:flex-none',
                    )}
                >
                    {(title || description) && (
                        <div className="flex flex-col gap-2.5">
                            {title && (
                                <h1 className="text-3xl leading-[1.14] font-semibold tracking-tight text-balance md:text-[2rem]">
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p className="text-[15px] leading-relaxed text-pretty text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                    )}

                    {children}
                </div>

                {footer && (
                    <div
                        className={cn(
                            'sticky bottom-0 z-10 -mx-5 flex flex-col gap-2.5 border-t bg-background px-5 pt-3.5 pb-[calc(1.375rem+var(--safe-area-bottom))]',
                            !pinFooter &&
                                'md:static md:mx-0 md:mt-7 md:border-0 md:px-0 md:pt-0 md:pb-0',
                        )}
                    >
                        {footer}
                    </div>
                )}
            </div>
        </main>
    );
}

/** The one way an onboarding step reports a failure. */
export function StepError({ children }: PropsWithChildren) {
    return (
        <p className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
            <AlertCircle className="size-4 shrink-0" />
            {children}
        </p>
    );
}

/**
 * A centred hint next to the primary action, e.g. a price or a time estimate.
 * `emphasis` is for the ones that carry a commitment — those must not read as
 * the smallest print on a screen whose main button says yes.
 */
export function StepNote({
    emphasis = false,
    children,
}: PropsWithChildren<{ emphasis?: boolean }>) {
    return (
        <p
            className={cn(
                'text-center text-[13px] leading-normal text-pretty',
                emphasis ? 'font-medium' : 'text-muted-foreground',
            )}
        >
            {children}
        </p>
    );
}

/** Onboarding controls are taller than the app's default so they stay easy to hit. */
export const stepControlClass = 'h-13 rounded-lg text-base md:text-base';

/**
 * Applies {@link stepControlClass} to every control inside, for forms built
 * from components shared with the rest of the app (which keep the smaller
 * default everywhere else). `[role=combobox]` covers both the select triggers
 * and the bank combobox's button.
 */
export const stepFormClass =
    '[&_[data-slot=input]]:h-13 [&_[data-slot=input]]:rounded-lg [&_[data-slot=input]]:text-base [&_[role=combobox]]:h-13 [&_[role=combobox]]:rounded-lg [&_[role=combobox]]:text-base';

/** Label + control, the form vocabulary used across the onboarding. */
export function StepField({
    label,
    htmlFor,
    children,
}: PropsWithChildren<{ label: string; htmlFor?: string }>) {
    return (
        <div className="flex flex-col gap-2">
            <label
                htmlFor={htmlFor}
                className="text-[13px] font-medium text-muted-foreground"
            >
                {label}
            </label>
            {children}
        </div>
    );
}
