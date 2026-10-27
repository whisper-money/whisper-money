import { type TokenScope } from '@/components/access-tokens/create-token-form';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { RefreshCw, Trash2, type LucideIcon } from 'lucide-react';

export interface AccessTokenRow {
    id: number;
    name: string;
    scope: TokenScope;
    created_at: string | null;
    last_used_at: string | null;
    /** Only sent by screens whose tokens can expire. */
    expires_at?: string | null;
    expired?: boolean;
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : __('Never');
}

/**
 * A token action that asks for confirmation first, since both rotating and
 * revoking cut off whatever uses the current secret.
 */
function ConfirmTokenAction({
    icon: Icon,
    label,
    title,
    description,
    destructive = false,
    onConfirm,
}: {
    icon: LucideIcon;
    label: string;
    title: string;
    description: string;
    destructive?: boolean;
    onConfirm: () => void;
}) {
    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className={destructive ? 'text-destructive' : undefined}
                >
                    <Icon className="h-4 w-4" />
                    {label}
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>{__('Cancel')}</AlertDialogCancel>
                    <AlertDialogAction onClick={onConfirm}>
                        {label}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * The user's tokens for one surface, each with its access, dates and the
 * rotate and revoke actions. `canRotate` hides rotating where the server
 * would refuse it; revoking is always offered.
 */
export function TokenList({
    tokens,
    rotateUrl,
    destroyUrl,
    canRotate = true,
}: {
    tokens: AccessTokenRow[];
    rotateUrl: (id: number) => string;
    destroyUrl: (id: number) => string;
    canRotate?: boolean;
}) {
    if (tokens.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {__('You have no tokens yet.')}
            </p>
        );
    }

    const visitOptions = { preserveScroll: true, preserveState: true };

    return (
        <ul className="divide-y">
            {tokens.map((token) => (
                <li
                    key={token.id}
                    className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                >
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{token.name}</span>
                            <Badge variant="outline">
                                {token.scope === 'read_write'
                                    ? __('Read & write')
                                    : __('Read only')}
                            </Badge>
                            {token.expired && (
                                <Badge variant="destructive">
                                    {__('Expired')}
                                </Badge>
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {__('Created')}: {formatDate(token.created_at)} ·{' '}
                            {__('Last used')}: {formatDate(token.last_used_at)}
                            {token.expires_at !== undefined && (
                                <>
                                    {' '}
                                    ·{' '}
                                    {token.expired
                                        ? __('Expired')
                                        : __('Expires')}
                                    : {formatDate(token.expires_at)}
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {canRotate && (
                            <ConfirmTokenAction
                                icon={RefreshCw}
                                label={__('Rotate')}
                                title={__('Rotate this token?')}
                                description={__(
                                    'Rotating gives you a new secret and cancels the old one. Anything using the current token stops working until you reconnect it with the new secret.',
                                )}
                                onConfirm={() =>
                                    router.post(
                                        rotateUrl(token.id),
                                        {},
                                        visitOptions,
                                    )
                                }
                            />
                        )}
                        <ConfirmTokenAction
                            icon={Trash2}
                            label={__('Revoke')}
                            title={__('Revoke this token?')}
                            description={__(
                                'Anything using this token loses access right away, and you cannot undo it.',
                            )}
                            destructive
                            onConfirm={() =>
                                router.delete(
                                    destroyUrl(token.id),
                                    visitOptions,
                                )
                            }
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}
