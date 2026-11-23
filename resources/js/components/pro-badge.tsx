import { Badge } from '@/components/ui/badge';

/**
 * The plan's name, as every pay surface writes it. A proper noun, so it reads
 * the same in every locale (the copy around it is translated, it is not).
 */
const PLAN_NAME = 'Standard';

/**
 * The badge used to mark paid-plan features across settings.
 */
export function ProBadge({ className }: { className?: string }) {
    return (
        <Badge variant="secondary" className={className}>
            {PLAN_NAME}
        </Badge>
    );
}
