import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-react';
import { type ReactNode } from 'react';

/** The table-head cell every import step's table labels its columns with. */
export const HEAD_CELL =
    'text-[11px] font-medium tracking-wider uppercase text-muted-foreground';

/** The line a table closes with: what the rows above it mean. */
export const FOOTNOTE =
    'border-t bg-muted px-4 py-2.5 text-xs text-muted-foreground';

/**
 * A band the import steps fold their secondary tables away into: a headline
 * with an optional chip, a hint while it is shut, and a rotating chevron.
 */
export function CollapsibleSection({
    open,
    onOpenChange,
    title,
    hint,
    className,
    triggerClassName,
    contentClassName,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Left of the trigger: the headline, and any chip beside it. */
    title: ReactNode;
    /** Right of the trigger while shut, from sm up. */
    hint?: ReactNode;
    className?: string;
    triggerClassName?: string;
    contentClassName?: string;
    children: ReactNode;
}) {
    return (
        <Collapsible
            open={open}
            onOpenChange={onOpenChange}
            className={cn('overflow-hidden rounded-lg border', className)}
        >
            <CollapsibleTrigger asChild>
                <Button
                    variant="ghost"
                    className={cn(
                        'flex h-auto w-full cursor-pointer items-center justify-between gap-3 rounded-none px-4 py-3 hover:bg-transparent',
                        triggerClassName,
                    )}
                >
                    <span className="flex items-center gap-2.5 text-left">
                        {title}
                    </span>
                    <span className="flex items-center gap-2">
                        {!open && hint && (
                            <span className="hidden text-xs font-normal text-muted-foreground sm:block">
                                {hint}
                            </span>
                        )}
                        <ChevronDown
                            className={`size-4 shrink-0 text-muted-foreground transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
                        />
                    </span>
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div className={cn('border-t', contentClassName)}>
                    {children}
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}
