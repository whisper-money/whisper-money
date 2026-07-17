import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';

/**
 * The "PRO" badge used to mark paid-plan features across settings.
 */
export function ProBadge({ className }: { className?: string }) {
    return (
        <Badge
            variant="secondary"
            className={cn(
                'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                className,
            )}
        >
            {__('PRO')}
        </Badge>
    );
}
