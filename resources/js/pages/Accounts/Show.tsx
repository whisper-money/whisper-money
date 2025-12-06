import { index, show } from '@/actions/App/Http/Controllers/AccountController';
import { AccountBalanceChart } from '@/components/accounts/account-balance-chart';
import { BalancesModal } from '@/components/accounts/balances-modal';
import { DeleteAccountDialog } from '@/components/accounts/delete-account-dialog';
import { EditAccountDialog } from '@/components/accounts/edit-account-dialog';
import { ImportBalancesDrawer } from '@/components/accounts/import-balances-drawer';
import { UpdateBalanceDialog } from '@/components/accounts/update-balance-dialog';
import { EncryptedText } from '@/components/encrypted-text';
import HeadingSmall from '@/components/heading-small';
import { TransactionList } from '@/components/transactions/transaction-list';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import {
    Account,
    Bank,
    formatAccountType,
    isTransactionalAccount,
} from '@/types/account';
import { Category } from '@/types/category';
import { Head } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

interface Props {
    account: Account;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
}

export default function AccountShow({
    account,
    categories,
    accounts,
    banks,
}: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [updateBalanceOpen, setUpdateBalanceOpen] = useState(false);
    const [importBalancesOpen, setImportBalancesOpen] = useState(false);
    const [balancesOpen, setBalancesOpen] = useState(false);
    const [chartRefreshKey, setChartRefreshKey] = useState(0);

    function handleBalanceUpdated() {
        setChartRefreshKey((prev) => prev + 1);
    }

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Accounts',
            href: index().url,
        },
        {
            title: (
                <EncryptedText
                    encryptedText={account.name}
                    iv={account.name_iv}
                    length={{ min: 5, max: 20 }}
                />
            ),
            href: show.url(account.id),
        },
    ];

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Account Details" />

            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4 pl-1">
                        {account.bank?.logo ? (
                            <img
                                src={account.bank.logo}
                                alt={account.bank.name}
                                className="size-12 rounded-full object-contain"
                            />
                        ) : (
                            <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                                <span className="text-lg font-medium text-muted-foreground">
                                    {account.bank?.name?.charAt(0) || '?'}
                                </span>
                            </div>
                        )}
                        <HeadingSmall
                            title={
                                <EncryptedText
                                    encryptedText={account.name}
                                    iv={account.name_iv}
                                    length={{ min: 8, max: 30 }}
                                />
                            }
                            description={`${account.bank?.name || 'Unknown Bank'} · ${formatAccountType(account.type)}`}
                        />
                    </div>

                    <ButtonGroup>
                        <ButtonGroup>
                            <Button
                                variant="outline"
                                onClick={() => setUpdateBalanceOpen(true)}
                            >
                                Update balance
                            </Button>
                        </ButtonGroup>
                        <ButtonGroup>
                            <Button
                                variant="outline"
                                onClick={() => setImportBalancesOpen(true)}
                            >
                                Import balances
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label="More options"
                                    >
                                        <ChevronDown className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onClick={() => setBalancesOpen(true)}
                                    >
                                        See balances
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        onClick={() => setEditOpen(true)}
                                    >
                                        Edit account
                                    </DropdownMenuItem>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onClick={() => setDeleteOpen(true)}
                                        variant="destructive"
                                    >
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </ButtonGroup>
                    </ButtonGroup>
                </div>

                <AccountBalanceChart
                    account={account}
                    refreshKey={chartRefreshKey}
                    onBalanceClick={() => setUpdateBalanceOpen(true)}
                />

                {isTransactionalAccount(account) && (
                    <TransactionList
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        accountId={account.id}
                        pageSize={10}
                        hideAccountFilter={true}
                        showActionsMenu={false}
                        maxHeight={600}
                        hideColumns={['bank', 'account']}
                    />
                )}
            </div>

            <EditAccountDialog
                account={account}
                open={editOpen}
                onOpenChange={setEditOpen}
                redirectTo={show.url(account.id)}
            />

            <DeleteAccountDialog
                account={account}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                redirectTo={index().url}
            />

            <UpdateBalanceDialog
                account={account}
                open={updateBalanceOpen}
                onOpenChange={setUpdateBalanceOpen}
                onSuccess={handleBalanceUpdated}
            />

            <BalancesModal
                account={account}
                open={balancesOpen}
                onOpenChange={setBalancesOpen}
                onBalanceChange={handleBalanceUpdated}
            />

            <ImportBalancesDrawer
                open={importBalancesOpen}
                onOpenChange={setImportBalancesOpen}
                accountId={account.id}
                onSuccess={handleBalanceUpdated}
            />
        </AppSidebarLayout>
    );
}
