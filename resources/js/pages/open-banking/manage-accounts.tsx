import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { SharedData } from '@/types';
import type { Account } from '@/types/account';
import { filterTransactionalAccounts } from '@/types/account';
import type { BankingConnection, DiscoveredBankAccount } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, MoreHorizontal, RefreshCw, Unplug } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    connection: BankingConnection;
    syncedAccounts: Account[];
    availableAccounts: Account[];
    discoveredAccounts: DiscoveredBankAccount[] | null;
}

export default function ManageAccountsPage({
    connection,
    syncedAccounts,
    availableAccounts,
    discoveredAccounts,
}: Props) {
    const { flash } = usePage<SharedData>().props;
    const [refreshing, setRefreshing] = useState(false);
    const [hasRefreshed, setHasRefreshed] = useState(false);
    const [discovered, setDiscovered] = useState<DiscoveredBankAccount[]>([]);

    useEffect(() => {
        if (flash?.error) {
            toast.error(flash.error);
        }
        if (flash?.success) {
            toast.success(flash.success);
        }
    }, [flash?.error, flash?.success]);

    // The discovered list only arrives when explicitly refreshed; keep it in
    // local state so per-account actions (which reload without ?refresh) don't
    // wipe it.
    useEffect(() => {
        if (discoveredAccounts) {
            setDiscovered(discoveredAccounts);
        }
    }, [discoveredAccounts]);

    const mapUrl = `/open-banking/connections/${connection.id}/accounts/map`;

    function compatibleAccounts(currency: string | null): Account[] {
        return filterTransactionalAccounts(
            availableAccounts.filter((a) => a.currency_code === currency),
        );
    }

    function postAction(url: string, data: Record<string, string | null>) {
        router.post(url, data, { preserveState: true, preserveScroll: true });
    }

    function handleRefresh() {
        router.reload({
            only: ['discoveredAccounts'],
            data: { refresh: 1 },
            onStart: () => setRefreshing(true),
            onFinish: () => {
                setRefreshing(false);
                setHasRefreshed(true);
            },
        });
    }

    function changeDestination(account: Account, targetId: string) {
        if (!account.external_account_id) {
            return;
        }
        postAction(mapUrl, {
            bank_account_uid: account.external_account_id,
            action: 'link',
            existing_account_id: targetId,
        });
    }

    function stopSyncing(account: Account) {
        postAction(
            `/open-banking/connections/${connection.id}/accounts/${account.id}/unlink`,
            {},
        );
    }

    function addDiscovered(
        discoveredAccount: DiscoveredBankAccount,
        existingAccountId: string | null,
    ) {
        postAction(mapUrl, {
            bank_account_uid: discoveredAccount.uid,
            action: existingAccountId ? 'link' : 'create',
            existing_account_id: existingAccountId,
            name: discoveredAccount.name,
            currency: discoveredAccount.currency,
            iban: discoveredAccount.iban,
        });
        setDiscovered((prev) =>
            prev.filter((d) => d.uid !== discoveredAccount.uid),
        );
    }

    const isActive = connection.status === 'active';

    return (
        <AppLayout>
            <Head title={__('Manage Accounts')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <div>
                        <Link
                            href="/settings/connections"
                            className="mb-2 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            {__('Connections')}
                        </Link>
                        <h3 className="text-lg font-medium">
                            {connection.aspsp_name}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {__(
                                'Choose which accounts from this bank are synced and where their transactions go.',
                            )}
                        </p>
                    </div>

                    <div className="space-y-3">
                        {syncedAccounts.length === 0 ? (
                            <Card>
                                <CardContent className="py-8 text-center text-sm text-muted-foreground">
                                    {__('No accounts are syncing yet.')}
                                </CardContent>
                            </Card>
                        ) : (
                            syncedAccounts.map((account) => (
                                <SyncedAccountCard
                                    key={account.id}
                                    account={account}
                                    targets={compatibleAccounts(
                                        account.currency_code,
                                    )}
                                    onChangeDestination={changeDestination}
                                    onStopSyncing={stopSyncing}
                                />
                            ))
                        )}
                    </div>

                    <div className="border-t pt-6">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                            <div>
                                <h4 className="text-sm font-medium">
                                    {__('Add accounts')}
                                </h4>
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'Load the latest list of accounts from your bank to sync more of them.',
                                    )}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                className="w-full sm:w-auto"
                                onClick={handleRefresh}
                                disabled={!isActive || refreshing}
                            >
                                {refreshing ? (
                                    <Spinner className="mr-1.5 size-3" />
                                ) : (
                                    <RefreshCw className="mr-1.5 h-3 w-3" />
                                )}
                                {__('Load accounts')}
                            </Button>
                        </div>

                        {!isActive && (
                            <p className="mt-3 text-sm text-muted-foreground">
                                {__(
                                    'Reconnect this bank to load its accounts again.',
                                )}
                            </p>
                        )}

                        {discovered.length > 0 && (
                            <div className="mt-4 space-y-3">
                                {discovered.map((discoveredAccount) => (
                                    <DiscoveredAccountCard
                                        key={discoveredAccount.uid}
                                        discoveredAccount={discoveredAccount}
                                        targets={compatibleAccounts(
                                            discoveredAccount.currency,
                                        )}
                                        onAdd={addDiscovered}
                                    />
                                ))}
                            </div>
                        )}

                        {hasRefreshed &&
                            !refreshing &&
                            discovered.length === 0 && (
                                <p className="mt-4 text-sm text-muted-foreground">
                                    {__('No new accounts available to add.')}
                                </p>
                            )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function SyncedAccountCard({
    account,
    targets,
    onChangeDestination,
    onStopSyncing,
}: {
    account: Account;
    targets: Account[];
    onChangeDestination: (account: Account, targetId: string) => void;
    onStopSyncing: (account: Account) => void;
}) {
    const [changing, setChanging] = useState(false);
    const [confirmingStop, setConfirmingStop] = useState(false);
    const otherTargets = targets.filter((t) => t.id !== account.id);

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <div className="flex items-center gap-2">
                    <BankLogo
                        src={account.bank?.logo}
                        name={account.bank?.name}
                        className="size-5"
                        fallback="letter"
                    />
                    <div>
                        <CardTitle className="text-base">
                            <AccountName account={account} />
                        </CardTitle>
                        <CardDescription>
                            {account.currency_code} &middot; {__('Syncing')}
                        </CardDescription>
                    </div>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon">
                            <MoreHorizontal className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {account.external_account_id &&
                            otherTargets.length > 0 && (
                                <DropdownMenuItem
                                    onSelect={() => setChanging(true)}
                                >
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                    {__('Change destination')}
                                </DropdownMenuItem>
                            )}
                        <DropdownMenuItem
                            onSelect={() => setConfirmingStop(true)}
                            className="text-destructive"
                        >
                            <Unplug className="mr-2 h-4 w-4" />
                            {__('Stop syncing')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </CardHeader>
            <AlertDialog open={confirmingStop} onOpenChange={setConfirmingStop}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {__('Stop syncing this account?')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {__(
                                'This account will stop syncing with your bank and become a manual account. Your existing transactions are kept, but new ones from the bank will no longer be imported. You can start syncing it again later.',
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{__('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => onStopSyncing(account)}
                        >
                            {__('Stop syncing')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            {changing && (
                <CardContent>
                    <Select
                        onValueChange={(value) => {
                            onChangeDestination(account, value);
                            setChanging(false);
                        }}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder={__('Move syncing to…')} />
                        </SelectTrigger>
                        <SelectContent>
                            {otherTargets.map((target) => (
                                <SelectItem key={target.id} value={target.id}>
                                    <AccountName account={target} />
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </CardContent>
            )}
        </Card>
    );
}

const CREATE_NEW_ACCOUNT = '__create__';

function DiscoveredAccountCard({
    discoveredAccount,
    targets,
    onAdd,
}: {
    discoveredAccount: DiscoveredBankAccount;
    targets: Account[];
    onAdd: (
        discoveredAccount: DiscoveredBankAccount,
        existingAccountId: string | null,
    ) => void;
}) {
    const displayName =
        discoveredAccount.name || discoveredAccount.iban || __('Bank Account');

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-4 space-y-0 pb-2">
                <div className="min-w-0">
                    <CardTitle className="truncate text-base">
                        {displayName}
                    </CardTitle>
                    <CardDescription>
                        {discoveredAccount.currency}
                        {discoveredAccount.iban &&
                            discoveredAccount.name &&
                            ` · ${discoveredAccount.iban}`}
                    </CardDescription>
                </div>
                <Select
                    onValueChange={(value) =>
                        onAdd(
                            discoveredAccount,
                            value === CREATE_NEW_ACCOUNT ? null : value,
                        )
                    }
                >
                    <SelectTrigger className="w-48 shrink-0">
                        <SelectValue placeholder={__('Select an account')} />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={CREATE_NEW_ACCOUNT}>
                            {__('Create new account')}
                        </SelectItem>
                        {targets.length > 0 && <SelectSeparator />}
                        {targets.map((target) => (
                            <SelectItem key={target.id} value={target.id}>
                                <AccountName account={target} />
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </CardHeader>
        </Card>
    );
}
