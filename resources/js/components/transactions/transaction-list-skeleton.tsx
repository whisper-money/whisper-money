import { Skeleton } from '@/components/ui/skeleton';

/** The table-shaped placeholder every transactions table shows while loading. */
export function TransactionListSkeleton() {
    return (
        <div className="space-y-4">
            <div className="overflow-hidden rounded-md border">
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
