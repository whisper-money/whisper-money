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
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Eye, EyeOff } from 'lucide-react';

interface AccountsManagerDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** All manageable accounts, in display order. */
    accounts: AccountWithMetrics[];
    isHidden: (account: AccountWithMetrics) => boolean;
    onReorder: (orderedIds: string[]) => void;
    onToggleVisibility: (id: string, hidden: boolean) => void;
}

export function AccountsManagerDialog({
    open,
    onOpenChange,
    accounts,
    isHidden,
    onReorder,
    onToggleVisibility,
}: AccountsManagerDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__('Edit accounts')}</DialogTitle>
                    <DialogDescription>
                        {__('Toggle visibility and drag to reorder.')}
                    </DialogDescription>
                </DialogHeader>

                <SortableGrid
                    className="flex flex-col gap-1"
                    items={accounts}
                    getId={(account) => account.id}
                    onReorder={onReorder}
                    renderItem={(account, dragHandle) => {
                        const hidden = isHidden(account);
                        return (
                            <div className="flex items-center gap-3 rounded-md px-2 py-2 hover:bg-muted">
                                <BankLogo
                                    src={account.bank?.logo ?? null}
                                    name={account.bank?.name}
                                    fallback="icon"
                                    className={cn(
                                        'size-7 shrink-0',
                                        hidden && 'opacity-40',
                                    )}
                                />
                                <AccountName
                                    account={account}
                                    className={cn(
                                        'flex-1 truncate text-sm',
                                        hidden && 'text-muted-foreground',
                                    )}
                                />
                                <button
                                    type="button"
                                    onClick={() =>
                                        onToggleVisibility(account.id, !hidden)
                                    }
                                    aria-label={
                                        hidden
                                            ? __('Show on dashboard')
                                            : __('Hide from dashboard')
                                    }
                                    aria-pressed={!hidden}
                                    className="text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {hidden ? (
                                        <EyeOff className="size-5" />
                                    ) : (
                                        <Eye className="size-5" />
                                    )}
                                </button>
                                {dragHandle}
                            </div>
                        );
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}
