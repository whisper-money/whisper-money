import {
    ConfirmTokenAction,
    confirmedActionVisitOptions,
    formatDate,
} from '@/components/access-tokens/confirm-action';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';

export interface ConnectedAppRow {
    id: string;
    name: string;
    /** Where the app sends the user back: what tells two same-named apps apart. */
    redirect: string | null;
    scopes: string[];
    connected_at: string | null;
    last_used_at: string | null;
}

/**
 * The OAuth apps connected to one surface (MCP or API), each with where it
 * redirects, its scopes, dates and a revoke action that disconnects it. `showLastUsed` is for
 * screens whose server knows when an app last used its access.
 */
export function ConnectedAppList({
    apps,
    description,
    destroyUrl,
    showLastUsed = false,
}: {
    apps: ConnectedAppRow[];
    description: string;
    destroyUrl: (id: string) => string;
    showLastUsed?: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{__('Connected apps')}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                {apps.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {__('No apps are connected.')}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {apps.map((app) => (
                            <li
                                key={app.id}
                                className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="space-y-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium break-all">
                                            {app.name}
                                        </span>
                                        {app.scopes.map((scope) => (
                                            <Badge
                                                key={scope}
                                                variant="outline"
                                            >
                                                {scope}
                                            </Badge>
                                        ))}
                                    </div>
                                    {app.redirect && (
                                        <p className="text-xs break-all text-muted-foreground">
                                            {app.redirect}
                                        </p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {__('Connected')}:{' '}
                                        {formatDate(app.connected_at)}
                                        {showLastUsed && (
                                            <>
                                                {' '}
                                                · {__('Last used')}:{' '}
                                                {formatDate(app.last_used_at)}
                                            </>
                                        )}
                                    </p>
                                </div>
                                <ConfirmTokenAction
                                    icon={Trash2}
                                    label={__('Revoke')}
                                    title={__('Disconnect this app?')}
                                    description={__(
                                        'The app loses access right away. To use it again, you will have to connect it and approve it again.',
                                    )}
                                    destructive
                                    onConfirm={() =>
                                        router.delete(
                                            destroyUrl(app.id),
                                            confirmedActionVisitOptions,
                                        )
                                    }
                                />
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}
