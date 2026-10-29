import {
    destroy,
    index as mcpIndex,
    rotate,
    store,
} from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import { CopyableCode } from '@/components/access-tokens/copyable-code';
import {
    CreateTokenForm,
    type TokenScope,
} from '@/components/access-tokens/create-token-form';
import { NewTokenAlert } from '@/components/access-tokens/new-token-alert';
import { ProUpsellAlert } from '@/components/access-tokens/pro-upsell-alert';
import {
    TokenList,
    type AccessTokenRow,
} from '@/components/access-tokens/token-list';
import HeadingSmall from '@/components/heading-small';
import { ProBadge } from '@/components/pro-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    MonitorSmartphone,
    ShieldAlert,
    Store,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

interface McpPageProps {
    tokens: AccessTokenRow[];
    serverUrl: string;
    oauthUrl: string;
    subscribeUrl: string;
    newToken: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'AI Connector', href: mcpIndex().url },
];

/**
 * An aside inside the "How to connect" card: a bold lead followed by the
 * detail, next to an icon.
 */
function CardNote({
    icon: Icon,
    title,
    children,
}: {
    icon: LucideIcon;
    title: string;
    children: ReactNode;
}) {
    return (
        <div className="flex gap-3 rounded-md border bg-muted/50 p-3 text-sm">
            <Icon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <p className="text-muted-foreground">
                <span className="font-medium text-foreground">{title}.</span>{' '}
                {children}
            </p>
        </div>
    );
}

/**
 * Installs the Claude Code plugin shipped from this repository: the marketplace
 * in .claude-plugin/marketplace.json, then the plugin it lists.
 */
const CLAUDE_CODE_PLUGIN_COMMANDS = [
    '/plugin marketplace add whisper-money/whisper-money',
    '/plugin install whisper-money@whisper-money',
];

type ConnectorApp = 'claude' | 'chatgpt';

export default function Mcp() {
    const { tokens, serverUrl, oauthUrl, subscribeUrl, newToken, auth } =
        usePage<SharedData & McpPageProps>().props;
    const [connector, setConnector] = useState<ConnectorApp>('claude');
    // A freshly minted token means the user just used the developer flow, so
    // keep that section open; otherwise it starts collapsed for everyone else.
    const [showDeveloper, setShowDeveloper] = useState<boolean>(
        Boolean(newToken),
    );

    const form = useForm<{ name: string; scope: TokenScope }>({
        name: '',
        scope: 'read',
    });

    function createToken(event: React.FormEvent) {
        event.preventDefault();
        // No preserveScroll: the one-time secret renders above the developer
        // section this form lives in, so staying put would hide it.
        form.post(store().url, {
            onSuccess: () => form.setData('name', ''),
        });
    }

    const oauthUrlBlock = <CopyableCode value={oauthUrl} className="pt-2" />;

    const connectors: Record<
        ConnectorApp,
        { label: string; steps: ReactNode[] }
    > = {
        claude: {
            label: __('Claude Desktop'),
            steps: [
                __('Open Settings → Connectors in Claude Desktop.'),
                __(
                    'Click "Add" in the top right, then "Add custom connector".',
                ),
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
                    'Turn on developer mode: Settings → Security and login → Developer mode.',
                ),
                __('In Plugins, click the + button in the top right.'),
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
                        <ProUpsellAlert
                            subscribeUrl={subscribeUrl}
                            description={__(
                                'You can set it up now, but it only works on a paid plan.',
                            )}
                        />
                    )}

                    <Alert>
                        <ShieldAlert className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                        <AlertTitle>
                            {__('Your data leaves Whisper Money')}
                        </AlertTitle>
                        <AlertDescription>
                            {__(
                                'Anything you ask about is sent to the AI app you connect, and we cannot control what it does with your data. Connect one only if you are comfortable with that. You can disconnect it any time to cut off access.',
                            )}
                        </AlertDescription>
                    </Alert>

                    {newToken && <NewTokenAlert token={newToken} />}

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
                            <CardNote
                                icon={MonitorSmartphone}
                                title={__('Set this up on a computer')}
                            >
                                {__(
                                    "Signing in and approving works fine in a desktop browser, but usually breaks in a phone's in-app browser. Once it's connected, you can chat with Whisper Money from Claude or ChatGPT on your phone as usual.",
                                )}
                            </CardNote>

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

                            {connector === 'chatgpt' && (
                                <CardNote
                                    icon={Store}
                                    title={__(
                                        "Whisper Money is in ChatGPT's app directory",
                                    )}
                                >
                                    {__(
                                        'You can connect it from there in a click, with no developer mode. Doing it by hand below is still worth the extra step: the listed version lags behind, and a tool that already works here can take weeks to reach it.',
                                    )}
                                </CardNote>
                            )}

                            <ol className="list-decimal space-y-3 pl-5 text-sm text-muted-foreground marker:font-medium marker:text-foreground">
                                {connectors[connector].steps.map(
                                    (step, index) => (
                                        <li key={index}>{step}</li>
                                    ),
                                )}
                            </ol>

                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'Connected apps can read, analyse and make changes to your data (transactions synced from your bank cannot be edited or deleted).',
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
                                                'For developers. Install our plugin and sign in through the browser, or connect with a token.',
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
                                    {/* Plugin: the recommended path, OAuth in the browser like Claude Desktop */}
                                    <div className="space-y-2">
                                        <div className="space-y-1">
                                            <h3 className="text-sm font-medium">
                                                {__('With the plugin')}
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                {__(
                                                    'Run these inside Claude Code to install the Whisper Money plugin.',
                                                )}
                                            </p>
                                        </div>
                                        {CLAUDE_CODE_PLUGIN_COMMANDS.map(
                                            (command) => (
                                                <CopyableCode
                                                    key={command}
                                                    value={command}
                                                />
                                            ),
                                        )}
                                        <p className="text-sm text-muted-foreground">
                                            {__(
                                                'Then run /mcp, pick Whisper Money and sign in through the browser. No token needed, and it can read and change your data.',
                                            )}
                                        </p>
                                    </div>

                                    {/* Token: read-only access or headless setups */}
                                    <div className="space-y-2">
                                        <div className="space-y-1">
                                            <h3 className="text-sm font-medium">
                                                {__('With a token')}
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                {__(
                                                    'For read-only access, or when Claude Code runs without a browser, such as on a server or in CI. Run this, using one of your tokens in place of <token>.',
                                                )}
                                            </p>
                                        </div>
                                        <CopyableCode
                                            value={`claude mcp add --transport http whisper-money ${serverUrl} --header "Authorization: Bearer <token>"`}
                                        />
                                    </div>

                                    {/* Create token */}
                                    <div className="space-y-2">
                                        <div className="space-y-1">
                                            <h3 className="text-sm font-medium">
                                                {__('Create a token')}
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                {__(
                                                    'Read-only tokens can analyse your data. Read & write tokens can also create, edit and delete transactions, categories, labels, budgets and automation rules.',
                                                )}
                                            </p>
                                        </div>
                                        <CreateTokenForm
                                            form={form}
                                            onSubmit={createToken}
                                            namePlaceholder={__(
                                                'e.g. Claude Desktop',
                                            )}
                                        />
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
                                        <TokenList
                                            tokens={tokens}
                                            rotateUrl={(id) => rotate(id).url}
                                            destroyUrl={(id) => destroy(id).url}
                                        />
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
