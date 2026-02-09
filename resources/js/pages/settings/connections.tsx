import { ConnectAccountDialog } from '@/components/open-banking/connect-account-dialog';
import { ConnectionStatusBadge } from '@/components/open-banking/connection-status-badge';
import { DisconnectDialog } from '@/components/open-banking/disconnect-dialog';
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
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import type { BankingConnection } from '@/types/banking';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';
import { MoreHorizontal, Plus, RefreshCw, Unplug } from 'lucide-react';
import { useState } from 'react';

interface Props {
    connections: BankingConnection[];
}

export default function ConnectionsPage({ connections }: Props) {
    const [connectDialogOpen, setConnectDialogOpen] = useState(false);
    const [disconnectConnection, setDisconnectConnection] =
        useState<BankingConnection | null>(null);

    function handleSync(connection: BankingConnection) {
        router.post(`/settings/connections/${connection.id}/sync`);
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
                    <div className="flex items-center justify-between">
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
                        <Button onClick={() => setConnectDialogOpen(true)}>
                            <Plus className="mr-2 h-4 w-4" />
                            {__('Connect Bank')}
                        </Button>
                    </div>

                    {connections.length === 0 ? (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-12">
                                <p className="text-muted-foreground">
                                    {__(
                                        'No bank connections yet. Connect a bank to automatically sync your transactions.',
                                    )}
                                </p>
                                <Button
                                    className="mt-4"
                                    variant="outline"
                                    onClick={() => setConnectDialogOpen(true)}
                                >
                                    {__('Connect Your First Bank')}
                                </Button>
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
                                            />
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
                                                        'active' && (
                                                        <DropdownMenuItem
                                                            onClick={() =>
                                                                handleSync(
                                                                    connection,
                                                                )
                                                            }
                                                        >
                                                            <RefreshCw className="mr-2 h-4 w-4" />
                                                            {__('Sync Now')}
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
                                            <span>
                                                {__('Last synced')}:{' '}
                                                {formatDate(
                                                    connection.last_synced_at,
                                                )}
                                            </span>
                                            {connection.valid_until && (
                                                <span>
                                                    {__('Expires')}:{' '}
                                                    {formatDate(
                                                        connection.valid_until,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                        {connection.error_message && (
                                            <p className="mt-2 text-sm text-destructive">
                                                {connection.error_message}
                                            </p>
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
            </SettingsLayout>
        </AppLayout>
    );
}
