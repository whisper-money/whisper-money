import {
    destroy,
    index as mcpIndex,
    rotate,
    store,
} from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import HeadingSmall from '@/components/heading-small';
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
import { Copy, KeyRound, RefreshCw, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';

interface TokenRow {
    id: number | string;
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
    { title: 'MCP access', href: mcpIndex().url },
];

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : __('Never');
}

export default function Mcp() {
    const { tokens, serverUrl, subscribeUrl, newToken, auth } =
        usePage<SharedData & McpPageProps>().props;
    const [, copy] = useClipboard();

    const form = useForm({ name: '', scope: 'read' });

    useEffect(() => {
        if (newToken) {
            copy(newToken).then((ok) => {
                if (ok) {
                    toast.success(__('Token copied to clipboard'));
                }
            });
        }
        // Only react to a freshly created token.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [newToken]);

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
            <Head title={__('MCP access')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-center gap-2">
                        <HeadingSmall
                            title={__('MCP access')}
                            description={__(
                                'Connect Whisper Money to your AI assistant (Claude, ChatGPT) to analyse your finances.',
                            )}
                        />
                        <Badge variant="secondary" className="tracking-widest">
                            {__('PRO')}
                        </Badge>
                    </div>

                    {!auth.hasProPlan && (
                        <Alert>
                            <AlertTitle>{__('This is a Pro feature')}</AlertTitle>
                            <AlertDescription>
                                {__(
                                    'You can create a token now, but MCP requests only work on a paid plan.',
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
                        <AlertTitle>{__('Your data leaves Whisper Money')}</AlertTitle>
                        <AlertDescription>
                            {__(
                                'The data you query is sent to whichever AI client you connect. Whisper Money cannot control what that client does with it. By connecting, you accept this. Revoke a token at any time to cut off access.',
                            )}
                        </AlertDescription>
                    </Alert>

                    {newToken && (
                        <Alert>
                            <KeyRound className="h-4 w-4" />
                            <AlertTitle>{__('Copy your new token now')}</AlertTitle>
                            <AlertDescription>
                                <p className="mb-2">
                                    {__(
                                        "This is the only time it is shown. Store it somewhere safe — you won't be able to see it again.",
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
                                    'Read-only tokens can only analyse data. Read & write tokens can also create and edit data.',
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
                                        {__('Scope')}
                                    </Label>
                                    <Select
                                        value={form.data.scope}
                                        onValueChange={(value) =>
                                            form.setData('scope', value)
                                        }
                                    >
                                        <SelectTrigger
                                            id="token-scope"
                                            className="w-full sm:w-56"
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
                                    'Rotate a token to replace a leaked secret, or revoke it to cut off access.',
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
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            rotate(token.id).url,
                                                            {},
                                                            {
                                                                preserveScroll:
                                                                    true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <RefreshCw className="h-4 w-4" />
                                                    {__('Rotate')}
                                                </Button>
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
                                                                    'Any AI client using this token will immediately lose access. This cannot be undone.',
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
                                                                            preserveScroll:
                                                                                true,
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
                                    'Your MCP server URL is below. Use it with a token you created above.',
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
                                    {__('Claude (web & desktop)')}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'Settings → Connectors → Add custom connector. Paste the URL above, choose "API key" authentication and paste your token.',
                                    )}
                                </p>
                            </div>

                            <div className="space-y-1">
                                <h3 className="text-sm font-medium">
                                    {__('Claude Code')}
                                </h3>
                                <code className="block overflow-x-auto rounded-md bg-muted px-3 py-2 text-sm">
                                    {`claude mcp add --transport http whisper-money ${serverUrl} --header "Authorization: Bearer <token>"`}
                                </code>
                            </div>

                            <div className="space-y-1">
                                <h3 className="text-sm font-medium">
                                    {__('ChatGPT')}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'Enable developer mode, then Settings → Connectors → Create. Paste the URL above and add an "Authorization: Bearer <token>" header.',
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
