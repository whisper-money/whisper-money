import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import { SortableGrid } from '@/components/sortable-grid';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { AccountWithMetrics } from '@/hooks/use-dashboard-data';
import { __ } from '@/utils/i18n';
import { Archive } from 'lucide-react';

interface AccountsManagerDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** All manageable accounts, in display order. */
    accounts: AccountWithMetrics[];
    onReorder: (orderedIds: string[]) => void;
    onArchive: (account: AccountWithMetrics) => void;
}

export function AccountsManagerDialog({
    open,
    onOpenChange,
    accounts,
    onReorder,
    onArchive,
}: AccountsManagerDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__('Edit accounts')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Drag to reorder. Archiving takes an account out of the whole app; bring it back from the Bank accounts settings.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <SortableGrid
                    className="flex flex-col gap-1"
                    items={accounts}
                    getId={(account) => account.id}
                    onReorder={onReorder}
                    renderItem={(account, dragHandle) => (
                        <div className="flex items-center gap-3 rounded-md px-2 py-2 hover:bg-muted">
                            <BankLogo
                                src={account.bank?.logo ?? null}
                                name={account.bank?.name}
                                fallback="icon"
                                className="size-7 shrink-0"
                            />
                            <AccountName
                                account={account}
                                className="flex-1 truncate text-sm"
                            />
                            <button
                                type="button"
                                onClick={() => onArchive(account)}
                                aria-label={__('Archive :name', {
                                    name: account.name,
                                })}
                                className="text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <Archive className="size-5" />
                            </button>
                            {dragHandle}
                        </div>
                    )}
                />
            </DialogContent>
        </Dialog>
    );
}
