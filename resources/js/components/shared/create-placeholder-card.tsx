import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Plus } from 'lucide-react';
import { ComponentProps } from 'react';

/**
 * The dimmed "add another one" card that trails a list of cards. Shared so the
 * budget dialog, the savings goal dialog and the Planning list's combined
 * create card cannot drift apart.
 *
 * It spreads the rest of its props onto the card because every call site wraps
 * it in an `asChild` trigger — a Radix `DialogTrigger` or `DropdownMenuTrigger`
 * hands the child its onClick and ref, and swallowing them leaves a card that
 * looks right and opens nothing.
 */
export function CreatePlaceholderCard({
    children,
    className,
    ...props
}: ComponentProps<'div'>) {
    return (
        <Card
            className={cn(
                'cursor-pointer opacity-50 transition-opacity duration-200 hover:opacity-100',
                className,
            )}
            {...props}
        >
            <CardContent className="flex h-full items-center justify-center">
                <div className="flex flex-row items-center justify-center gap-1">
                    <Plus className="mr-2 h-4 w-4" />
                    {children}
                </div>
            </CardContent>
        </Card>
    );
}
