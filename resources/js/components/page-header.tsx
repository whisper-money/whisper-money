import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface PageHeaderProps {
    /** The page's name: the only `<h1>` on the page. */
    title: ReactNode;
    /** One line under the title. A string renders as a paragraph; a node (badges, meta) renders as is, in the same muted text. */
    description?: ReactNode;
    /** Drawn before the title, such as a bank logo. */
    media?: ReactNode;
    /** The page's actions, on the right. They wrap under the title when the row is too narrow for both. */
    actions?: ReactNode;
    /** Sizes the actions' row, e.g. `w-full sm:w-auto` for controls that fill the phone's width once they wrap under the title. */
    actionsClassName?: string;
    className?: string;
}

/**
 * The title block at the top of an app page (docs/design-system.md, Page
 * headers). The header bar above already shows the breadcrumbs on desktop;
 * on a phone it doesn't, so the title is bigger there and is what names the
 * page.
 */
export function PageHeader({
    title,
    description,
    media,
    actions,
    actionsClassName,
    className,
}: PageHeaderProps) {
    return (
        <header
            data-slot="page-header"
            className={cn(
                'flex flex-wrap items-start justify-between gap-x-4 gap-y-3',
                className,
            )}
        >
            <div className="flex min-w-0 flex-1 basis-48 items-center gap-4">
                {media}
                {/* min-w-0 + anywhere: a long name without spaces wraps
                    inside the page instead of pushing it sideways. */}
                <div className="flex min-w-0 flex-col gap-1 [overflow-wrap:anywhere]">
                    <h1 className="text-[26px] leading-tight font-semibold tracking-tight md:text-xl">
                        {title}
                    </h1>
                    {typeof description === 'string' ? (
                        <p className="text-sm text-pretty text-muted-foreground">
                            {description}
                        </p>
                    ) : (
                        description && (
                            <div className="text-sm text-muted-foreground">
                                {description}
                            </div>
                        )
                    )}
                </div>
            </div>
            {actions && (
                <div
                    className={cn(
                        'flex max-w-full flex-wrap items-center gap-2',
                        actionsClassName,
                    )}
                >
                    {actions}
                </div>
            )}
        </header>
    );
}
