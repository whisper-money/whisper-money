import { cn } from '@/lib/utils';
import documentation from '@/routes/documentation';
import { ExternalLink } from 'lucide-react';
import { type ReactNode } from 'react';

interface DocGuideLinkProps {
    /** The documentation page, e.g. `import-from-another-app/banktrack`. */
    slug: string;
    /** A heading id on that page, without the `#`. */
    anchor?: string;
    className?: string;
    children: ReactNode;
}

/**
 * A link from inside a flow to its documentation page. It opens in a new tab,
 * so leaving for the guide keeps whatever the flow has already read.
 */
export function DocGuideLink({
    slug,
    anchor,
    className,
    children,
}: DocGuideLinkProps) {
    const url = documentation.show.url(slug);

    return (
        <a
            href={anchor ? `${url}#${anchor}` : url}
            target="_blank"
            rel="noopener noreferrer"
            className={cn(
                'inline-flex items-center gap-1.5 text-sm font-medium text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500',
                className,
            )}
        >
            {children}
            <ExternalLink className="size-3.5 shrink-0" aria-hidden="true" />
        </a>
    );
}
