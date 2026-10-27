import { Skeleton } from '@/components/ui/skeleton';
import { PHONE_FRAME } from '@/lib/compact-list-style';
import { cn } from '@/lib/utils';

/**
 * The placeholder every transactions table shows while loading: shaped like
 * the table, and below md like the compact list a phone gets, in the same
 * frame as the list it stands in for.
 */
export function TransactionListSkeleton({
    framedOnPhone = false,
}: {
    /** The list sits inside a card: keep the plain frame on a phone too. */
    framedOnPhone?: boolean;
}) {
    return (
        <div className="space-y-4">
            <div
                className={cn(
                    'overflow-hidden rounded-md border',
                    !framedOnPhone && PHONE_FRAME,
                )}
            >
                <TableShape />
                <ListShape />
            </div>
            <div className="flex items-center justify-between">
                <Skeleton className="h-5 w-32" />
                <Skeleton className="h-9 w-48" />
            </div>
        </div>
    );
}

function TableShape() {
    return (
        <div className="max-md:hidden" data-testid="transaction-skeleton-table">
            <div className="grid grid-cols-4 gap-4 border-b p-4">
                <Skeleton className="h-5 w-8" />
                <Skeleton className="h-5 w-24" />
                <Skeleton className="h-5 w-64" />
                <Skeleton className="h-5 w-20 justify-self-end" />
            </div>
            <div className="divide-y">
                <div className="bg-muted/50 px-4 py-2">
                    <Skeleton className="h-4 w-32" />
                </div>
                {Array.from({ length: 6 }).map((_, index) => (
                    <div key={index} className="grid grid-cols-4 gap-4 p-4">
                        <Skeleton className="h-4 w-8" />
                        <Skeleton className="h-4 w-28" />
                        <div className="space-y-2">
                            <Skeleton className="h-4 w-full" />
                            <Skeleton className="h-3 w-48" />
                        </div>
                        <Skeleton className="h-4 w-20 justify-self-end" />
                    </div>
                ))}
            </div>
        </div>
    );
}

/** A day band and its rows, at the sizes `TransactionListRow` draws them. */
function ListShape() {
    return (
        <div className="md:hidden" data-testid="transaction-skeleton-list">
            <div className="flex items-center justify-between border-b border-neutral-100 bg-neutral-50 px-4 pt-2.5 pb-1.5 dark:border-neutral-800 dark:bg-neutral-900">
                <Skeleton className="h-3.5 w-28" />
                <Skeleton className="h-3.5 w-16" />
            </div>
            {Array.from({ length: 6 }).map((_, index) => (
                <div
                    key={index}
                    className="flex items-start gap-3 border-b border-neutral-100 px-4 py-3 dark:border-neutral-800"
                >
                    <Skeleton className="size-10 shrink-0 rounded-full" />
                    <div className="flex flex-1 flex-col gap-2 pt-0.5">
                        <div className="flex justify-between gap-2">
                            <Skeleton className="h-4 w-36" />
                            <Skeleton className="h-4 w-16" />
                        </div>
                        <Skeleton className="h-3.5 w-28" />
                    </div>
                </div>
            ))}
        </div>
    );
}
