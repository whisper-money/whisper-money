import { ConnectAccountDialog } from '@/components/open-banking/connect-account-dialog';
import { ConnectionStatusBadge } from '@/components/open-banking/connection-status-badge';
import { DisconnectDialog } from '@/components/open-banking/disconnect-dialog';
import { UpdateCredentialsDialog } from '@/components/open-banking/update-credentials-dialog';
import { UpgradeDialog } from '@/components/subscription/upgrade-dialog';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { CreateButton } from '@/components/ui/create-button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { CONNECT_PROVIDERS } from '@/lib/connect-providers';
import { getCsrfToken } from '@/lib/csrf';
import type { SharedData } from '@/types';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { Head, router, usePage, usePoll } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowRight,
    KeyRound,
    MoreHorizontal,
    RefreshCw,
    RotateCcw,
    Unplug,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    connections: BankingConnection[];
}

export default function ConnectionsPage({ connections }: Props) {
    const { auth, flash, subscriptionsEnabled } = usePage<SharedData>().props;
    const isDemoAccount = auth?.isDemoAccount ?? false;
    const isFreePlan = subscriptionsEnabled && !auth?.hasProPlan;
    const [connectDialogOpen, setConnectDialogOpen] = useState(false);
    const [upgradeDialogOpen, setUpgradeDialogOpen] = useState(false);
    const [disconnectConnection, setDisconnectConnection] =
        useState<BankingConnection | null>(null);
    const [updateCredentialsConnection, setUpdateCredentialsConnection] =
        useState<BankingConnection | null>(null);
    const [reconnectingId, setReconnectingId] = useState<string | null>(null);

    const hasSyncing = connections.some(
        (c) => c.status === 'active' && !c.last_synced_at,
    );

    const { start, stop } = usePoll(5000, {}, { autoStart: false });

    useEffect(() => {
        if (flash?.error) {
            toast.error(flash.error);
        }
        if (flash?.success) {
            toast.success(flash.success);
        }
    }, [flash?.error, flash?.success]);

    useEffect(() => {
        if (hasSyncing) {
            start();
        } else {
            stop();
        }
    }, [hasSyncing, start, stop]);

    function handleSync(connection: BankingConnection) {
        router.post(`/settings/connections/${connection.id}/sync`);
    }

    async function handleReconnect(connection: BankingConnection) {
        setReconnectingId(connection.id);
        try {
            const response = await fetch(
                `/open-banking/connections/${connection.id}/reauthorize`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                },
            );

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));

                if (typeof data.redirect === 'string') {
                    window.location.href = data.redirect;
                    return;
                }

                throw new Error(
                    data.error || __('Failed to start re-authorization.'),
                );
            }

            const data = await response.json();
            window.location.href = data.redirect_url;
        } catch (e) {
            toast.error(
                e instanceof Error
                    ? e.message
                    : __('Failed to reconnect. Please try again.'),
            );
            setReconnectingId(null);
        }
    }

    function isApiKeyProvider(connection: BankingConnection): boolean {
        return CONNECT_PROVIDERS.some(
            (provider) => provider.providerKey === connection.provider,
        );
    }

    function hasAuthError(connection: BankingConnection): boolean {
        return (
            connection.status === 'error' &&
            isApiKeyProvider(connection) &&
            (connection.error_message?.includes('Authentication failed') ??
                false)
        );
    }

    function isEnableBankingAuthError(connection: BankingConnection): boolean {
        return (
            connection.status === 'error' &&
            connection.provider === 'enablebanking' &&
            (connection.error_message?.includes('Authentication failed') ??
                false)
        );
    }

    function canReconnect(connection: BankingConnection): boolean {
        return (
            connection.provider === 'enablebanking' &&
            (connection.status === 'expired' ||
                isEnableBankingAuthError(connection))
        );
    }

    function canManageAccounts(connection: BankingConnection): boolean {
        return (
            connection.provider === 'enablebanking' &&
            ['active', 'expired', 'error'].includes(connection.status)
        );
    }

    function formatDate(dateString: string | null): string {
        if (!dateString) return __('Never');
        return new Date(dateString).toLocaleDateString(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    return (
        <AppLayout>
            <Head title={__('Connections')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-center justify-between gap-4">
                        <div>
                            <h3 className="text-lg font-medium">
                                {__('Bank Connections')}
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'Manage your connected bank accounts for automatic transaction syncing.',
                                )}
                            </p>
                        </div>
                        <CreateButton
                            onClick={() =>
                                isFreePlan
                                    ? setUpgradeDialogOpen(true)
                                    : setConnectDialogOpen(true)
                            }
                            disabled={isDemoAccount}
                        >
                            {__('Connect Bank')}
                        </CreateButton>
                    </div>

                    {connections.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12">
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'No bank connections yet. Connect a bank to automatically sync your transactions.',
                                    )}
                                </p>
                            </CardContent>
                        </Card>
                    ) : (
                        <div className="space-y-3">
                            {connections.map((connection) => (
                                <Card key={connection.id}>
                                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                                        <div className="space-y-1">
                                            <CardTitle className="text-base">
                                                {connection.aspsp_name}
                                            </CardTitle>
                                            <CardDescription>
                                                {connection.aspsp_country}{' '}
                                                &middot;{' '}
                                                {connection.accounts_count}{' '}
                                                {connection.accounts_count === 1
                                                    ? __('account')
                                                    : __('accounts')}
                                            </CardDescription>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <ConnectionStatusBadge
                                                status={connection.status}
                                                lastSyncedAt={
                                                    connection.last_synced_at
                                                }
                                            />
                                            {connection.status === 'expired' &&
                                                canReconnect(connection) && (
                                                    <Button
                                                        size="sm"
                                                        disabled={
                                                            reconnectingId ===
                                                            connection.id
                                                        }
                                                        onClick={() =>
                                                            handleReconnect(
                                                                connection,
                                                            )
                                                        }
                                                    >
                                                        {reconnectingId ===
                                                        connection.id ? (
                                                            <Spinner className="mr-1.5 size-3" />
                                                        ) : (
                                                            <RotateCcw className="mr-1.5 h-3 w-3" />
                                                        )}
                                                        {__('Reconnect')}
                                                    </Button>
                                                )}
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                    >
                                                        <MoreHorizontal className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    {connection.status ===
                                                        'awaiting_mapping' && (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                router.visit(
                                                                    `/open-banking/connections/${connection.id}/map-accounts`,
                                                                )
                                                            }
                                                        >
                                                            <ArrowRight className="mr-2 h-4 w-4" />
                                                            {__('Map Accounts')}
                                                        </DropdownMenuItem>
                                                    )}
                                                    {canManageAccounts(
                                                        connection,
                                                    ) && (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                router.visit(
                                                                    `/open-banking/connections/${connection.id}/accounts`,
                                                                )
                                                            }
                                                        >
                                                            <Wallet className="mr-2 h-4 w-4" />
                                                            {__(
                                                                'Manage Accounts',
                                                            )}
                                                        </DropdownMenuItem>
                                                    )}
                                                    {hasAuthError(
                                                        connection,
                                                    ) && (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                setUpdateCredentialsConnection(
                                                                    connection,
                                                                )
                                                            }
                                                        >
                                                            <KeyRound className="mr-2 h-4 w-4" />
                                                            {__(
                                                                'Update Credentials',
                                                            )}
                                                        </DropdownMenuItem>
                                                    )}
                                                    {canReconnect(
                                                        connection,
                                                    ) && (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                handleReconnect(
                                                                    connection,
                                                                )
                                                            }
                                                            disabled={
                                                                reconnectingId ===
                                                                connection.id
                                                            }
                                                        >
                                                            <RotateCcw className="mr-2 h-4 w-4" />
                                                            {__('Reconnect')}
                                                        </DropdownMenuItem>
                                                    )}
                                                    {(connection.status ===
                                                        'active' ||
                                                        (connection.status ===
                                                            'error' &&
                                                            !isEnableBankingAuthError(
                                                                connection,
                                                            ))) && (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                handleSync(
                                                                    connection,
                                                                )
                                                            }
                                                        >
                                                            <RefreshCw className="mr-2 h-4 w-4" />
                                                            {connection.status ===
                                                            'error'
                                                                ? __('Retry')
                                                                : __(
                                                                      'Sync Now',
                                                                  )}
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuItem
                                                        onClick={() =>
                                                            setDisconnectConnection(
                                                                connection,
                                                            )
                                                        }
                                                        className="text-destructive"
                                                    >
                                                        <Unplug className="mr-2 h-4 w-4" />
                                                        {__('Disconnect')}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="flex gap-6 text-sm text-muted-foreground">
                                            {connection.status ===
                                            'awaiting_mapping' ? (
                                                <span>
                                                    {__(
                                                        'Accounts need to be mapped before syncing can begin.',
                                                    )}
                                                </span>
                                            ) : connection.status ===
                                                  'active' &&
                                              !connection.last_synced_at ? (
                                                <span className="flex items-center gap-1.5">
                                                    <Spinner className="size-3" />
                                                    {[
                                                        'indexacapital',
                                                        'interactivebrokers',
                                                    ].includes(
                                                        connection.provider,
                                                    )
                                                        ? __(
                                                              'Syncing balances…',
                                                          )
                                                        : __(
                                                              'Syncing transactions and balances…',
                                                          )}
                                                </span>
                                            ) : (
                                                <span>
                                                    {__('Last synced')}:{' '}
                                                    {formatDate(
                                                        connection.last_synced_at,
                                                    )}
                                                </span>
                                            )}
                                            {connection.valid_until && (
                                                <span>
                                                    {__('Expires')}:{' '}
                                                    {formatDate(
                                                        connection.valid_until,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        {connection.status === 'error' && (
                                            <div className="mt-3 rounded-lg border border-destructive/30 bg-destructive/5 p-3 dark:bg-destructive/10">
                                                <div className="flex items-start gap-2">
                                                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0 text-destructive dark:text-destructive-foreground" />
                                                    <div className="space-y-2">
                                                        <p className="text-sm text-destructive dark:text-foreground">
                                                            {connection.error_message ??
                                                                __(
                                                                    'An unexpected error occurred during sync.',
                                                                )}
                                                        </p>
                                                        <div className="flex flex-wrap items-center gap-3">
                                                            {hasAuthError(
                                                                connection,
                                                            ) && (
                                                                <Button
                                                                    variant="secondary"
                                                                    size="sm"
                                                                    className="h-7 text-xs"
                                                                    onClick={() =>
                                                                        setUpdateCredentialsConnection(
                                                                            connection,
                                                                        )
                                                                    }
                                                                >
                                                                    <KeyRound className="mr-1.5 h-3 w-3" />
                                                                    {__(
                                                                        'Update Credentials',
                                                                    )}
                                                                </Button>
                                                            )}
                                                            {isEnableBankingAuthError(
                                                                connection,
                                                            ) ? (
                                                                <Button
                                                                    variant="secondary"
                                                                    size="sm"
                                                                    className="h-7 text-xs"
                                                                    disabled={
                                                                        reconnectingId ===
                                                                        connection.id
                                                                    }
                                                                    onClick={() =>
                                                                        handleReconnect(
                                                                            connection,
                                                                        )
                                                                    }
                                                                >
                                                                    {reconnectingId ===
                                                                    connection.id ? (
                                                                        <Spinner className="mr-1.5 size-3" />
                                                                    ) : (
                                                                        <RotateCcw className="mr-1.5 h-3 w-3" />
                                                                    )}
                                                                    {__(
                                                                        'Reconnect',
                                                                    )}
                                                                </Button>
                                                            ) : (
                                                                <Button
                                                                    variant="secondary"
                                                                    size="sm"
                                                                    className="h-7 text-xs"
                                                                    onClick={() =>
                                                                        handleSync(
                                                                            connection,
                                                                        )
                                                                    }
                                                                >
                                                                    <RefreshCw className="mr-1.5 h-3 w-3" />
                                                                    {__(
                                                                        'Retry',
                                                                    )}
                                                                </Button>
                                                            )}
                                                            <a
                                                                href="https://discord.gg/m8hUhx6D9D"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                                className="inline-flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                                                            >
                                                                {__(
                                                                    'Need help? Join our Discord',
                                                                )}
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </div>

                <ConnectAccountDialog
                    open={connectDialogOpen}
                    onOpenChange={setConnectDialogOpen}
                    connections={connections}
                />

                <UpgradeDialog
                    open={upgradeDialogOpen}
                    onOpenChange={setUpgradeDialogOpen}
                    title={__('Bank connections are a paid feature')}
                    description={__(
                        'Subscribe to a plan to automatically sync your transactions and balances straight from your bank.',
                    )}
                    source="connections"
                />

                {disconnectConnection && (
                    <DisconnectDialog
                        connection={disconnectConnection}
                        open={!!disconnectConnection}
                        onOpenChange={(open) => {
                            if (!open) setDisconnectConnection(null);
                        }}
                    />
                )}

                {updateCredentialsConnection && (
                    <UpdateCredentialsDialog
                        connection={updateCredentialsConnection}
                        open={!!updateCredentialsConnection}
                        onOpenChange={(open) => {
                            if (!open) setUpdateCredentialsConnection(null);
                        }}
                    />
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
