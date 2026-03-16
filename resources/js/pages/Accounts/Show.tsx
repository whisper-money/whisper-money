import { index, show } from '@/actions/App/Http/Controllers/AccountController';
import { AccountBalanceChart } from '@/components/accounts/account-balance-chart';
import { BalancesModal } from '@/components/accounts/balances-modal';
import { DeleteAccountDialog } from '@/components/accounts/delete-account-dialog';
import { EditAccountDialog } from '@/components/accounts/edit-account-dialog';
import { ImportBalancesDrawer } from '@/components/accounts/import-balances-drawer';
import { UpdateBalanceDialog } from '@/components/accounts/update-balance-dialog';
import { BankLogo } from '@/components/bank-logo';
import HeadingSmall from '@/components/heading-small';
import { MobileBackButton } from '@/components/mobile-back-button';
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
import { AutomationRule } from '@/types/automation-rule';
import { Category } from '@/types/category';
import { Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

interface Props {
    account: Account;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels?: Label[];
    automationRules?: AutomationRule[];
}

export default function AccountShow({
    account,
    categories,
    accounts,
    banks,
    labels = [],
    automationRules = [],
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

    const isConnected = !!account.banking_connection_id;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Accounts',
            href: index().url,
        },
        {
            title: account.name,

            href: show.url(account.id),
        },
    ];

    return (
        <AppSidebarLayout
            breadcrumbs={breadcrumbs}
            mobileLeading={<MobileBackButton href={index().url} />}
        >
            <Head title={__('Account Details')} />

            <div className="space-y-6 p-6">
                <div className="sm flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div className="flex items-center gap-4 pl-1">
                        <BankLogo
                            src={account.bank?.logo}
                            name={account.bank?.name}
                            className="size-12"
                            fallback="letter"
                        />
                        <HeadingSmall
                            title={account.name}
                            description={`${account.bank?.name || 'Unknown Bank'} · ${formatAccountType(account.type)}`}
                        />
                    </div>

                    {isConnected ? (
                        <Button
                            variant="outline"
                            onClick={() => setEditOpen(true)}
                        >
                            {__('Edit account')}
                        </Button>
                    ) : (
                        <ButtonGroup>
                            <ButtonGroup>
                                <Button
                                    variant="outline"
                                    onClick={() => setUpdateBalanceOpen(true)}
                                >
                                    {__('Update balance')}
                                </Button>
                            </ButtonGroup>
                            <ButtonGroup>
                                <Button
                                    variant="outline"
                                    onClick={() => setImportBalancesOpen(true)}
                                >
                                    {__('Import balances')}
                                </Button>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            aria-label={__('More options')}
                                        >
                                            <ChevronDown className="h-4 w-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem
                                            onClick={() =>
                                                setBalancesOpen(true)
                                            }
                                        >
                                            {__('See balances')}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onClick={() => setEditOpen(true)}
                                        >
                                            {__('Edit account')}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onClick={() => setDeleteOpen(true)}
                                            variant="destructive"
                                        >
                                            {__('Delete')}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </ButtonGroup>
                        </ButtonGroup>
                    )}
                </div>

                <AccountBalanceChart
                    account={account}
                    refreshKey={chartRefreshKey}
                    onBalanceClick={
                        isConnected
                            ? undefined
                            : () => setUpdateBalanceOpen(true)
                    }
                />

                {isTransactionalAccount(account) && (
                    <TransactionList
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        labels={labels}
                        automationRules={automationRules}
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
                accounts={accounts}
                accountId={account.id}
                onSuccess={handleBalanceUpdated}
            />
        </AppSidebarLayout>
    );
}
