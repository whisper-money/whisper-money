import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { ReactNode } from 'react';

interface Props {
    /** Detail page the title and the footer button both link to. */
    href: string;
    title: string;
    /** Rendered top-right: the budget's period type, the goal's status. */
    badge?: ReactNode;
    /** Rendered under the title, already laid out as an icon + text row. */
    description?: ReactNode;
    /** Rendered footer-left, opposite "View Details". */
    footerStart?: ReactNode;
    /** The progress readout: a bar for budgets, a ring for savings goals. */
    children: ReactNode;
    /** Greys the card out. Archived items are read-only leftovers. */
    dimmed?: boolean;
}

/**
 * The shell both cards on the Planning list share. They sit in one mixed grid,
 * so everything except the progress readout has to be identical between them —
 * keeping that in one place is also what stops the duplication check tripping.
 */
export function PlanningCard({
    href,
    title,
    badge,
    description,
    footerStart,
    children,
    dimmed = false,
}: Props) {
    return (
        <Card className={cn(dimmed && 'opacity-60')}>
            <CardHeader>
                <div className="flex items-start justify-between">
                    <div className="space-y-1">
                        <CardTitle className="text-xl">
                            <Link
                                href={href}
                                className="-my-1 -ml-1.5 inline-flex items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                            >
                                {title}
                            </Link>
                        </CardTitle>
                        {description && (
                            <CardDescription className="flex items-center gap-2">
                                {description}
                            </CardDescription>
                        )}
                    </div>
                    {badge}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {children}

                <div
                    className={cn(
                        'flex items-center gap-2 border-t pt-4',
                        footerStart ? 'justify-between' : 'justify-end',
                    )}
                >
                    {footerStart}
                    <Link href={href}>
                        <Button
                            className="cursor-pointer"
                            variant="ghost"
                            size="sm"
                        >
                            {__('View Details')}

                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Button>
                    </Link>
                </div>
            </CardContent>
        </Card>
    );
}
