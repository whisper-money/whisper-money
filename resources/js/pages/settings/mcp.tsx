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
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useClipboard } from '@/hooks/use-clipboard';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    Copy,
    KeyRound,
    MonitorSmartphone,
    RefreshCw,
    ShieldAlert,
    Trash2,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
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
    oauthUrl: string;
    subscribeUrl: string;
    newToken: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Connector', href: mcpIndex().url },
];

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleString() : __('Never');
}

type ConnectorApp = 'claude' | 'chatgpt';

export default function Mcp() {
    const { tokens, serverUrl, oauthUrl, subscribeUrl, newToken, auth } =
        usePage<SharedData & McpPageProps>().props;
    const [, copy] = useClipboard();
    const [connector, setConnector] = useState<ConnectorApp>('claude');
    // A freshly minted token means the user just used the developer flow, so
    // keep that section open; otherwise it starts collapsed for everyone else.
    const [showDeveloper, setShowDeveloper] = useState<boolean>(
        Boolean(newToken),
    );

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

    const oauthUrlBlock = (
        <div className="flex items-center gap-2 pt-2">
            <code className="flex-1 overflow-x-auto rounded-md bg-muted px-3 py-2 text-sm">
                {oauthUrl}
            </code>
            <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => copyValue(oauthUrl)}
                aria-label={__('Copy')}
            >
                <Copy className="h-4 w-4" />
            </Button>
        </div>
    );

    const connectors: Record<
        ConnectorApp,
        { label: string; steps: ReactNode[] }
    > = {
        claude: {
            label: __('Claude Desktop'),
            steps: [
                __('Open Settings → Connectors in Claude Desktop.'),
                __('Click "Add custom connector".'),
                <>
                    {__('Give it a name and paste this URL:')}
                    {oauthUrlBlock}
                </>,
                __(
                    'Approve the connection on the Whisper Money screen that opens.',
                ),
            ],
        },
        chatgpt: {
            label: __('ChatGPT'),
            steps: [
                __(
                    'Turn on developer mode: Settings → Connectors → Advanced → Developer mode. Custom connectors are only available with it on.',
                ),
                __('In Connectors, click "Create".'),
                <>
                    {__('Give it a name and paste this URL:')}
                    {oauthUrlBlock}
                </>,
                __(
                    'Approve the connection on the Whisper Money screen that opens.',
                ),
            ],
        },
    };

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
                                    'You can set it up now, but it only works on a paid plan.',
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

                    {/* Connect Claude Desktop / ChatGPT — the primary flow */}
                    <Card>
                        <CardHeader>
                            <CardTitle>{__('How to connect')}</CardTitle>
                            <CardDescription>
                                {__(
                                    'Pick your app, then follow the steps. You sign in and approve the connection — no token needed.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="flex gap-3 rounded-md border bg-muted/50 p-3 text-sm">
                                <MonitorSmartphone className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                                <p className="text-muted-foreground">
                                    <span className="font-medium text-foreground">
                                        {__('Set this up on a computer')}.
                                    </span>{' '}
                                    {__(
                                        "Signing in and approving works fine in a desktop browser, but usually breaks in a phone's in-app browser. Once it's connected, you can chat with Whisper Money from Claude or ChatGPT on your phone as usual.",
                                    )}
                                </p>
                            </div>

                            <ToggleGroup
                                type="single"
                                value={connector}
                                onValueChange={(value) =>
                                    value && setConnector(value as ConnectorApp)
                                }
                                variant="outline"
                                className="w-full"
                            >
                                {(
                                    Object.keys(connectors) as ConnectorApp[]
                                ).map((key) => (
                                    <ToggleGroupItem
                                        key={key}
                                        value={key}
                                        className="flex-1 cursor-pointer aria-checked:bg-primary/10"
                                    >
                                        {connectors[key].label}
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>

                            <ol className="list-decimal space-y-3 pl-5 text-sm text-muted-foreground marker:font-medium marker:text-foreground">
                                {connectors[connector].steps.map(
                                    (step, index) => (
                                        <li key={index}>{step}</li>
                                    ),
                                )}
                            </ol>

                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'Connected apps can read, analyse and make changes to your data (bank-connected accounts stay read-only).',
                                )}
                            </p>
                        </CardContent>
                    </Card>

                    {/* Developer flow: Claude Code + token management, hidden by default */}
                    <Collapsible
                        open={showDeveloper}
                        onOpenChange={setShowDeveloper}
                    >
                        <Card className="gap-0 py-0">
                            <CollapsibleTrigger asChild>
                                <button
                                    type="button"
                                    className="flex w-full cursor-pointer items-center justify-between gap-4 px-6 py-6 text-left"
                                >
                                    <span className="flex flex-col gap-1.5">
                                        <span className="leading-none font-semibold">
                                            {__('Connect with Claude Code')}
                                        </span>
                                        <span className="text-sm text-muted-foreground">
                                            {__(
                                                'For developers. Claude Code signs in with a token instead of the browser flow.',
                                            )}
                                        </span>
                                    </span>
                                    <ChevronDown
                                        className={cn(
                                            'h-5 w-5 shrink-0 text-muted-foreground transition-transform duration-200',
                                            showDeveloper && 'rotate-180',
                                        )}
                                    />
                                </button>
                            </CollapsibleTrigger>
                            <CollapsibleContent>
                                <CardContent className="space-y-6 pb-6">
                                    <div className="space-y-1">
                                        <p className="text-sm text-muted-foreground">
                                            {__(
                                                'Run this, using one of your tokens in place of <token>.',
                                            )}
                                        </p>
                                        <code className="block overflow-x-auto rounded-md bg-muted px-3 py-2 text-sm">
                                            {`claude mcp add --transport http whisper-money ${serverUrl} --header "Authorization: Bearer <token>"`}
                                        </code>
                                    </div>

                                    {/* Create token */}
                                    <div className="space-y-2">
                                        <div className="space-y-1">
                                            <h3 className="text-sm font-medium">
                                                {__('Create a token')}
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                {__(
                                                    'Read-only tokens can analyse your data. Read & write tokens can also create, edit and delete transactions, categories, labels and automation rules.',
                                                )}
                                            </p>
                                        </div>
                                        <form
                                            onSubmit={createToken}
                                            className="flex flex-col gap-4 pt-2 sm:flex-row sm:items-end"
                                        >
                                            <div className="flex-1 space-y-2">
                                                <Label htmlFor="token-name">
                                                    {__('Name')}
                                                </Label>
                                                <Input
                                                    id="token-name"
                                                    value={form.data.name}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'name',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder={__(
                                                        'e.g. Claude Desktop',
                                                    )}
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
                                                        onValueChange={(
                                                            value,
                                                        ) =>
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
                                                                {__(
                                                                    'Read only',
                                                                )}
                                                            </SelectItem>
                                                            <SelectItem value="read_write">
                                                                {__(
                                                                    'Read & write',
                                                                )}
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
                                    </div>

                                    {/* Token list */}
                                    <div className="space-y-2">
                                        <div className="space-y-1">
                                            <h3 className="text-sm font-medium">
                                                {__('Your tokens')}
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                {__(
                                                    'Rotate a token if it leaks, or revoke it to cut off access.',
                                                )}
                                            </p>
                                        </div>
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
                                                                        ? __(
                                                                              'Read & write',
                                                                          )
                                                                        : __(
                                                                              'Read only',
                                                                          )}
                                                                </Badge>
                                                            </div>
                                                            <p className="text-xs text-muted-foreground">
                                                                {__('Created')}:{' '}
                                                                {formatDate(
                                                                    token.created_at,
                                                                )}{' '}
                                                                ·{' '}
                                                                {__(
                                                                    'Last used',
                                                                )}
                                                                :{' '}
                                                                {formatDate(
                                                                    token.last_used_at,
                                                                )}
                                                            </p>
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <AlertDialog>
                                                                <AlertDialogTrigger
                                                                    asChild
                                                                >
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                    >
                                                                        <RefreshCw className="h-4 w-4" />
                                                                        {__(
                                                                            'Rotate',
                                                                        )}
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
                                                                            {__(
                                                                                'Cancel',
                                                                            )}
                                                                        </AlertDialogCancel>
                                                                        <AlertDialogAction
                                                                            onClick={() =>
                                                                                router.post(
                                                                                    rotate(
                                                                                        token.id,
                                                                                    )
                                                                                        .url,
                                                                                    {},
                                                                                    {
                                                                                        preserveScroll: true,
                                                                                        preserveState: true,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            {__(
                                                                                'Rotate',
                                                                            )}
                                                                        </AlertDialogAction>
                                                                    </AlertDialogFooter>
                                                                </AlertDialogContent>
                                                            </AlertDialog>
                                                            <AlertDialog>
                                                                <AlertDialogTrigger
                                                                    asChild
                                                                >
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        className="text-destructive"
                                                                    >
                                                                        <Trash2 className="h-4 w-4" />
                                                                        {__(
                                                                            'Revoke',
                                                                        )}
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
                                                                            {__(
                                                                                'Cancel',
                                                                            )}
                                                                        </AlertDialogCancel>
                                                                        <AlertDialogAction
                                                                            onClick={() =>
                                                                                router.delete(
                                                                                    destroy(
                                                                                        token.id,
                                                                                    )
                                                                                        .url,
                                                                                    {
                                                                                        preserveScroll: true,
                                                                                        preserveState: true,
                                                                                    },
                                                                                )
                                                                            }
                                                                        >
                                                                            {__(
                                                                                'Revoke',
                                                                            )}
                                                                        </AlertDialogAction>
                                                                    </AlertDialogFooter>
                                                                </AlertDialogContent>
                                                            </AlertDialog>
                                                        </div>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </CardContent>
                            </CollapsibleContent>
                        </Card>
                    </Collapsible>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
