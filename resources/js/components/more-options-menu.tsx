import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/utils/i18n';
import { ChevronDown } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * A page header's "More options" menu: the actions that belong to the page's
 * subject as a whole, and are used too rarely to earn a button of their own.
 */
export function MoreOptionsMenu({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="icon"
                    className={className}
                    aria-label={__('More options')}
                    data-test="more-options"
                >
                    <ChevronDown className="h-4 w-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">{children}</DropdownMenuContent>
        </DropdownMenu>
    );
}
