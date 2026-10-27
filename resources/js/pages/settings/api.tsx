import {
    index as apiIndex,
    destroy,
    destroyApp,
    rotate,
    store,
} from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import {
    ConnectedAppList,
    type ConnectedAppRow,
} from '@/components/access-tokens/connected-app-list';
import { CopyableCode } from '@/components/access-tokens/copyable-code';
import {
    CreateTokenForm,
    TokenOptionSelect,
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
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { BookOpen, Plus } from 'lucide-react';
import { useState } from 'react';

type TokenExpiry = '30_days' | '90_days' | '1_year' | 'never';

interface ApiPageProps {
    tokens: AccessTokenRow[];
    connectedApps: ConnectedAppRow[];
    baseUrl: string;
    docsUrl: string;
    subscribeUrl: string;
    newToken: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'API', href: apiIndex().url }];

/**
 * The create-token dialog: name, access and expiry (90 days by default).
 * Closes once the token is created, so the one-time secret shows on the page.
 */
function CreateApiTokenDialog({ disabled }: { disabled: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        name: string;
        scope: TokenScope;
        expiry: TokenExpiry;
    }>({
        name: '',
        scope: 'read',
        expiry: '90_days',
    });

    function createToken(event: React.FormEvent) {
        event.preventDefault();
        form.post(store().url, {
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" disabled={disabled}>
                    <Plus className="h-4 w-4" />
                    {__('Create token')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__('Create an API token')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Read-only tokens can read your data. Read & write tokens can also create, edit and delete it.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <CreateTokenForm
                    form={form}
                    onSubmit={createToken}
                    namePlaceholder={__('e.g. Budget spreadsheet')}
                    layout="stacked"
                >
                    <TokenOptionSelect
                        id="token-expiry"
                        label={__('Expiration')}
                        value={form.data.expiry}
                        onChange={(expiry) => form.setData('expiry', expiry)}
                        options={[
                            { value: '30_days', label: __('30 days') },
                            { value: '90_days', label: __('90 days') },
                            { value: '1_year', label: __('1 year') },
                            { value: 'never', label: __('No expiration') },
                        ]}
                        layout="stacked"
                    />
                </CreateTokenForm>
            </DialogContent>
        </Dialog>
    );
}

export default function Api() {
    const {
        tokens,
        connectedApps,
        baseUrl,
        docsUrl,
        subscribeUrl,
        newToken,
        auth,
    } = usePage<SharedData & ApiPageProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('API')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <HeadingSmall
                            title={__('API')}
                            description={__(
                                'Use the Whisper Money API to build your own scripts and integrations on top of your data.',
                            )}
                        />
                        <ProBadge className="mt-1 shrink-0" />
                    </div>

                    {!auth.hasProPlan && (
                        <ProUpsellAlert
                            subscribeUrl={subscribeUrl}
                            description={__(
                                'The API is available on a paid plan. Upgrade to create or rotate tokens; you can still revoke the ones you have.',
                            )}
                        />
                    )}

                    {newToken && <NewTokenAlert token={newToken} />}

                    <Card>
                        <CardHeader>
                            <CardTitle>{__('Base URL')}</CardTitle>
                            <CardDescription>
                                {__(
                                    'Send every request to this URL, with your token in the Authorization header as a Bearer token.',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <CopyableCode value={baseUrl} />
                            <Link
                                href={docsUrl}
                                className="inline-flex items-center gap-2 text-sm font-medium underline"
                            >
                                <BookOpen className="h-4 w-4" />
                                {__('Read the API documentation')}
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-start justify-between gap-4">
                            <div className="space-y-1.5">
                                <CardTitle>{__('Personal tokens')}</CardTitle>
                                <CardDescription>
                                    {__(
                                        'Rotate a token if it leaks, or revoke it to cut off access.',
                                    )}
                                </CardDescription>
                            </div>
                            <CreateApiTokenDialog disabled={!auth.hasProPlan} />
                        </CardHeader>
                        <CardContent>
                            <TokenList
                                tokens={tokens}
                                rotateUrl={(id) => rotate(id).url}
                                destroyUrl={(id) => destroy(id).url}
                                showExpiry
                                canRotate={auth.hasProPlan}
                            />
                        </CardContent>
                    </Card>

                    <ConnectedAppList
                        apps={connectedApps}
                        description={__(
                            'Apps you signed in to with your Whisper Money account. Revoke one to cut off its access.',
                        )}
                        destroyUrl={(id) => destroyApp(id).url}
                        showLastUsed
                    />
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
