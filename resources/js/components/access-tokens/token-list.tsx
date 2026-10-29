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
import { toast } from 'sonner';

export interface AccessTokenRow {
    id: number;
    name: string;
    scope: TokenScope;
    created_at: string | null;
    last_used_at: string | null;
    /** Sent by screens whose tokens can expire (see `showExpiry`). */
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
 * rotate and revoke actions. `showExpiry` adds each token's expiry, for
 * screens whose tokens can expire; `canRotate` hides rotating where the server
 * would refuse it, as does an expired token, while revoking is always offered.
 */
export function TokenList({
    tokens,
    rotateUrl,
    destroyUrl,
    showExpiry = false,
    canRotate = true,
}: {
    tokens: AccessTokenRow[];
    rotateUrl: (id: number) => string;
    destroyUrl: (id: number) => string;
    showExpiry?: boolean;
    canRotate?: boolean;
}) {
    if (tokens.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {__('You have no tokens yet.')}
            </p>
        );
    }

    // A refused rotation (the shared-account block, an expired token) comes
    // back as an error bag; say why instead of closing the dialog silently.
    const visitOptions = {
        preserveScroll: true,
        preserveState: true,
        onError: (errors: Record<string, string>) =>
            toast.error(
                Object.values(errors)[0] ?? __('Something went wrong.'),
            ),
    };

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
                            {showExpiry && (
                                <>
                                    {' '}
                                    ·{' '}
                                    {token.expired
                                        ? __('Expired')
                                        : __('Expires')}
                                    : {formatDate(token.expires_at ?? null)}
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {canRotate && !token.expired && (
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
