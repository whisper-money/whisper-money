import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/** The reader's plan, next to their name: solid for Pro, outlined for Free. */
export function PlanBadge({
    label,
    isPro,
    className,
}: {
    label: string;
    isPro: boolean;
    className?: string;
}) {
    return (
        <Badge
            variant={isPro ? 'default' : 'outline'}
            className={cn('ml-auto rounded-full', className)}
            data-testid="user-menu-plan"
        >
            {label}
        </Badge>
    );
}
