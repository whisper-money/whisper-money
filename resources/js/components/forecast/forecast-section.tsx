import HeadingSmall from '@/components/heading-small';
import { type ReactNode } from 'react';

/**
 * A block of the forecast page under its own heading: the title and its
 * explanation sit above the card, not inside it, with what belongs to the
 * whole block (actions, a total) at the heading's right.
 */
export function ForecastSection({
    title,
    description,
    aside,
    children,
}: {
    title: string;
    description: string;
    /** At the heading's right; it wraps under it when the row is too narrow. */
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                <div className="min-w-0 flex-1 basis-64">
                    <HeadingSmall title={title} description={description} />
                </div>
                {aside && (
                    <div className="flex flex-wrap items-center gap-3">
                        {aside}
                    </div>
                )}
            </div>
            {children}
        </section>
    );
}
