import { Skeleton } from '@/components/ui/skeleton';
import { PHONE_BLEED } from '@/lib/phone-bleed';
import { cn } from '@/lib/utils';

/**
 * The table-shaped placeholder every transactions table shows while loading,
 * edge to edge on a phone like the list it stands in for.
 */
export function TransactionListSkeleton({
    framedOnPhone = false,
}: {
    /** The list sits inside a card: keep the frame on a phone too. */
    framedOnPhone?: boolean;
}) {
    return (
        <div className="space-y-4">
            <div
                className={cn(
                    'overflow-hidden rounded-md border',
                    !framedOnPhone && PHONE_BLEED,
                )}
            >
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
            <div className="flex items-center justify-between">
                <Skeleton className="h-5 w-32" />
                <Skeleton className="h-9 w-48" />
            </div>
        </div>
    );
}
