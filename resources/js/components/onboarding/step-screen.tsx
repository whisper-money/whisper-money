import { cn } from '@/lib/utils';
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
    children,
}: PropsWithChildren<StepScreenProps>) {
    return (
        <main className="flex flex-1 flex-col px-5 md:items-center md:px-7">
            <div
                className={cn(
                    'flex w-full flex-1 flex-col md:flex-none md:py-20',
                    width === 'md' ? 'md:max-w-md' : 'md:max-w-xl',
                )}
            >
                <div
                    className={cn(
                        'flex flex-1 flex-col gap-7 pt-3 md:flex-none md:pt-0',
                        align === 'center' && 'justify-center',
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
                    <div className="sticky bottom-0 z-10 -mx-5 flex flex-col gap-2.5 border-t bg-background px-5 pt-3.5 pb-[calc(1.375rem+var(--safe-area-bottom))] md:static md:mx-0 md:mt-7 md:border-0 md:px-0 md:pt-0 md:pb-0">
                        {footer}
                    </div>
                )}
            </div>
        </main>
    );
}

/** A centred hint next to the primary action, e.g. a price or a time estimate. */
export function StepNote({ children }: PropsWithChildren) {
    return (
        <p className="text-center text-[13px] leading-normal text-pretty text-muted-foreground">
            {children}
        </p>
    );
}

/** Onboarding controls are taller than the app's default so they stay easy to hit. */
export const stepControlClass = 'h-13 rounded-lg text-base';

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
