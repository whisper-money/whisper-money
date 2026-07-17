import {
    destroy,
    index as mcpIndex,
    rotate,
    store,
} from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import HeadingSmall from '@/components/heading-small';
import { ProBadge } from '@/components/pro-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Copy, KeyRound, RefreshCw, ShieldAlert, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

interface TokenRow {
    id: number;
    name: string;
    scope: 'read' | 'read_write';
    created_at: string | null;
    last_used_at: string | null;
}

interface McpPageProps {
    tokens: TokenRow[];
    serverUrl: string;
    subscribeUrl: string;
    newToken: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Connector', href: mcpIndex().url },
];

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : __('Never');
}

export default function Mcp() {
    const { tokens, serverUrl, subscribeUrl, newToken, auth } = usePage<
        SharedData & McpPageProps
    >().props;
    const [, copy] = useClipboard();

    const form = useForm<{ name: string; scope: 'read' | 'read_write' }>({
        name: '',
        scope: 'read',
    });

    function createToken(event: React.FormEvent) {
        event.preventDefault();
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => form.setData('name', ''),
        });
    }

    function copyValue(value: string) {
        copy(value).then((ok) => {
            if (ok) {
                toast.success(__('Copied to clipboard'));
            }
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('AI Connector')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <HeadingSmall
                            title={__('AI Connector')}
                            description={__(
                                'Connect Whisper Money to your AI assistant (Claude, ChatGPT) to analyse your finances.',
                            )}
                        />
                        <ProBadge className="mt-1 shrink-0" />
                    </div>

                    {!auth.hasProPlan && (
                        <Alert>
                            <AlertTitle>
                                {__('This is a Pro feature')}
                            </AlertTitle>
                            <AlertDescription>
                                {__(
                                    'You can create a token now, but it only works on a paid plan.',
                                )}{' '}
                                <Link
                                    href={subscribeUrl}
                                    className="font-medium underline"
                                >
                                    {__('Upgrade your account')}
                                </Link>
                            </AlertDescription>
                        </Alert>
                    )}

                    <Alert>
                        <ShieldAlert className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                        <AlertTitle>
                            {__('Your data leaves Whisper Money')}
                        </AlertTitle>
                        <AlertDescription>
                            {__(
                                'Anything you ask about is sent to the AI app you connect, and we cannot control what it does with your data. Connect one only if you are comfortable with that. You can revoke a token any time to cut off access.',
                            )}
                        </AlertDescription>
                    </Alert>

                    {newToken && (
                        <Alert>
                            <KeyRound className="h-4 w-4" />
                            <AlertTitle>
                                {__('Copy your new token now')}
                            </AlertTitle>
                            <AlertDescription>
                                <p className="mb-2">
                                    {__(
                                        'This is the only time you will see it, so copy it somewhere safe now.',
                                    )}
                                </p>
                                <div className="flex items-center gap-2">
                                    <code className="flex-1 overflow-x-auto rounded-md bg-muted px-3 py-2 text-sm">
                                        {newToken}
                                    </code>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        onClick={() => copyValue(newToken)}
                                        aria-label={__('Copy')}
                                    >
                                        <Copy className="h-4 w-4" />
                                    </Button>
                                </div>
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* Create token */}
                    <Card>
                        <CardHeader>
                            <CardTitle>{__('Create a token')}</CardTitle>
                            <CardDescription>
                                {__(
                                    'Read-only tokens can analyse your data. Read & write tokens can also create, edit and delete transactions, categories, labels and automation rules.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={createToken}
                                className="flex flex-col gap-4 sm:flex-row sm:items-end"
                            >
                                <div className="flex-1 space-y-2">
                                    <Label htmlFor="token-name">
                                        {__('Name')}
                                    </Label>
                                    <Input
                                        id="token-name"
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                        placeholder={__('e.g. Claude Desktop')}
                                        required
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="token-scope">
                                        {__('Access')}
                                    </Label>
                                    {/* Radix Select renders a hidden native <select> next to the
                                        trigger; wrap it so the parent's space-y never pushes that
                                        hidden node below the trigger and breaks the row alignment. */}
                                    <div className="w-full sm:w-48">
                                        <Select
                                            value={form.data.scope}
                                            onValueChange={(value) =>
                                                form.setData(
                                                    'scope',
                                                    value as
                                                        | 'read'
                                                        | 'read_write',
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                id="token-scope"
                                                className="w-full"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="read">
                                                    {__('Read only')}
                                                </SelectItem>
                                                <SelectItem value="read_write">
                                                    {__('Read & write')}
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {__('Create token')}
                                </Button>
                            </form>
                            {form.errors.name && (
                                <p className="mt-2 text-sm text-destructive">
                                    {form.errors.name}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Token list */}
                    <Card>
                        <CardHeader>
                            <CardTitle>{__('Your tokens')}</CardTitle>
                            <CardDescription>
                                {__(
                                    'Rotate a token if it leaks, or revoke it to cut off access.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {tokens.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {__('You have no tokens yet.')}
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {tokens.map((token) => (
                                        <li
                                            key={token.id}
                                            className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">
                                                        {token.name}
                                                    </span>
                                                    <Badge variant="outline">
                                                        {token.scope ===
                                                        'read_write'
                                                            ? __('Read & write')
                                                            : __('Read only')}
                                                    </Badge>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {__('Created')}:{' '}
                                                    {formatDate(
                                                        token.created_at,
                                                    )}{' '}
                                                    · {__('Last used')}:{' '}
                                                    {formatDate(
                                                        token.last_used_at,
                                                    )}
                                                </p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <AlertDialog>
                                                    <AlertDialogTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                        >
                                                            <RefreshCw className="h-4 w-4" />
                                                            {__('Rotate')}
                                                        </Button>
                                                    </AlertDialogTrigger>
                                                    <AlertDialogContent>
                                                        <AlertDialogHeader>
                                                            <AlertDialogTitle>
                                                                {__(
                                                                    'Rotate this token?',
                                                                )}
                                                            </AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                {__(
                                                                    'Rotating gives you a new secret and cancels the old one. Anything using the current token stops working until you reconnect it with the new secret.',
                                                                )}
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter>
                                                            <AlertDialogCancel>
                                                                {__('Cancel')}
                                                            </AlertDialogCancel>
                                                            <AlertDialogAction
                                                                onClick={() =>
                                                                    router.post(
                                                                        rotate(
                                                                            token.id,
                                                                        ).url,
                                                                        {},
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                {__('Rotate')}
                                                            </AlertDialogAction>
                                                        </AlertDialogFooter>
                                                    </AlertDialogContent>
                                                </AlertDialog>
                                                <AlertDialog>
                                                    <AlertDialogTrigger asChild>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="text-destructive"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                            {__('Revoke')}
                                                        </Button>
                                                    </AlertDialogTrigger>
                                                    <AlertDialogContent>
                                                        <AlertDialogHeader>
                                                            <AlertDialogTitle>
                                                                {__(
                                                                    'Revoke this token?',
                                                                )}
                                                            </AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                {__(
                                                                    'Anything using this token loses access right away, and you cannot undo it.',
                                                                )}
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter>
                                                            <AlertDialogCancel>
                                                                {__('Cancel')}
                                                            </AlertDialogCancel>
                                                            <AlertDialogAction
                                                                onClick={() =>
                                                                    router.delete(
                                                                        destroy(
                                                                            token.id,
                                                                        ).url,
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                {__('Revoke')}
                                                            </AlertDialogAction>
                                                        </AlertDialogFooter>
                                                    </AlertDialogContent>
                                                </AlertDialog>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    {/* Connection instructions */}
                    <Card>
                        <CardHeader>
                            <CardTitle>{__('How to connect')}</CardTitle>
                            <CardDescription>
                                {__(
                                    'Here is your connection URL. Use it with a token you created above.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <div className="flex items-center gap-2">
                                <code className="flex-1 overflow-x-auto rounded-md bg-muted px-3 py-2 text-sm">
                                    {serverUrl}
                                </code>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={() => copyValue(serverUrl)}
                                    aria-label={__('Copy')}
                                >
                                    <Copy className="h-4 w-4" />
                                </Button>
                            </div>

                            <div className="space-y-1">
                                <h3 className="text-sm font-medium">
                                    {__('Claude Code')}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'Run this, using one of your tokens in place of <token>.',
                                    )}
                                </p>
                                <code className="block overflow-x-auto rounded-md bg-muted px-3 py-2 text-sm">
                                    {`claude mcp add --transport http whisper-money ${serverUrl} --header "Authorization: Bearer <token>"`}
                                </code>
                            </div>

                            <div className="space-y-1">
                                <h3 className="text-sm font-medium">
                                    {__('Claude Desktop & ChatGPT')}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'Coming soon. These apps sign in with OAuth, which we are still building, so a token will not work with them yet. Use Claude Code for now.',
                                    )}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
