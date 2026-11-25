import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { type TransactionRowAction } from '@/lib/transaction-row-actions';
import { __ } from '@/utils/i18n';
import { type ComponentProps, Fragment } from 'react';

/**
 * The dropdown of a transaction's row actions, the same in the table's
 * actions column and in the edit dialog on a phone.
 */
export function TransactionRowDropdownMenuContent({
    actions,
    ...props
}: { actions: TransactionRowAction[] } & ComponentProps<
    typeof DropdownMenuContent
>) {
    return (
        <DropdownMenuContent {...props}>
            <DropdownMenuLabel>{__('Actions')}</DropdownMenuLabel>
            {actions.map((action) => (
                <Fragment key={action.id}>
                    {action.separated && <DropdownMenuSeparator />}
                    <DropdownMenuItem
                        variant={action.variant}
                        onClick={action.onSelect}
                    >
                        {action.label}
                    </DropdownMenuItem>
                </Fragment>
            ))}
        </DropdownMenuContent>
    );
}
