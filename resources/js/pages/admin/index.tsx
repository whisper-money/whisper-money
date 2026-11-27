import { index as usersIndex } from '@/actions/App/Http/Controllers/Admin/UserController';
import { index, run } from '@/actions/App/Http/Controllers/AdminController';
import {
    IntegrationRequestList,
    type AdminIntegrationRequest,
} from '@/components/admin/integration-request-list';
import {
    SignupChart,
    type SignupChartPoint,
} from '@/components/admin/signup-chart';
import { AdminUserTable, type AdminUser } from '@/components/admin/user-table';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Form, Head, Link } from '@inertiajs/react';
import { ArrowRight, Check, Copy, Play } from 'lucide-react';
import { useState } from 'react';

type CommandResult = {
    command: string;
    exit_code: number;
    output: string;
    duration_ms: number;
    ran_at: string;
};

/** Group label to its command lines, each mapped to whether it takes arguments. */
type CommandGroups = Record<string, Record<string, boolean>>;

interface Props {
    commands: CommandGroups;
    signupChart: SignupChartPoint[];
    latestUsers: AdminUser[];
    userCount: number;
    integrationRequests: AdminIntegrationRequest[];
    result: CommandResult | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin', href: index().url }];

/** 44px hit targets on a phone, the usual 36px from `sm` up. */
const CONTROL_HEIGHT = 'h-11 sm:h-9';

function formatDuration(ms: number): string {
    return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`;
}

function CopyOutputButton({ output }: { output: string }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard.writeText(output).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            onClick={copy}
            aria-label={__('Copy output')}
        >
            {copied ? (
                <Check className="size-3.5" />
            ) : (
                <Copy className="size-3.5" />
            )}
        </Button>
    );
}

function CommandOutput({ result }: { result: CommandResult }) {
    return (
        <div className="mt-4 overflow-hidden rounded-md border">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b bg-muted px-3 py-2">
                <code className="w-full font-mono text-xs break-all text-muted-foreground sm:w-auto">
                    php artisan {result.command}
                </code>
                <Badge
                    variant={result.exit_code === 0 ? 'success' : 'destructive'}
                >
                    {__('exit')} {result.exit_code}
                </Badge>
                <span className="text-xs text-muted-foreground">
                    {formatDuration(result.duration_ms)} ·{' '}
                    {new Date(result.ran_at).toLocaleTimeString([], {
                        hour: '2-digit',
                        minute: '2-digit',
                    })}
                </span>
                <div className="ml-auto">
                    <CopyOutputButton output={result.output} />
                </div>
            </div>
            {/* Verbatim: a failure keeps whatever the command managed to print. */}
            <pre className="max-h-44 overflow-auto p-3 font-mono text-xs leading-5 whitespace-pre">
                {result.output}
            </pre>
        </div>
    );
}

function CommandRunner({
    commands,
    result,
}: {
    commands: CommandGroups;
    result: CommandResult | null;
}) {
    const takesArguments: Record<string, boolean> = Object.assign(
        {},
        ...Object.values(commands),
    );
    const [command, setCommand] = useState(Object.keys(takesArguments)[0]);

    return (
        <section>
            <HeadingSmall
                title={__('Run a command')}
                description={__('Only the commands listed here can be run.')}
            />

            <Form
                {...run.form()}
                options={{ preserveScroll: true, preserveState: true }}
                className="mt-4"
                data-test="run-command-form"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div className="flex flex-col gap-2 sm:w-80">
                                <Label htmlFor="command">{__('Command')}</Label>
                                <Select
                                    name="command"
                                    value={command}
                                    onValueChange={setCommand}
                                    required
                                >
                                    <SelectTrigger
                                        id="command"
                                        className={CONTROL_HEIGHT}
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {Object.entries(commands).map(
                                            ([group, lines]) => (
                                                <SelectGroup key={group}>
                                                    {/* Server-side group name;
                                                        not a translatable key. */}
                                                    <SelectLabel>
                                                        {group}
                                                    </SelectLabel>
                                                    {Object.keys(lines).map(
                                                        (line) => (
                                                            <SelectItem
                                                                key={line}
                                                                value={line}
                                                                className="font-mono"
                                                            >
                                                                {line}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectGroup>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-2 sm:max-w-md sm:flex-1">
                                <Label htmlFor="arguments">
                                    {__('Arguments')}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        {__('(optional)')}
                                    </span>
                                </Label>
                                <Input
                                    id="arguments"
                                    name="arguments"
                                    className={CONTROL_HEIGHT}
                                    placeholder={
                                        takesArguments[command]
                                            ? '--history-days=7'
                                            : __('This command takes none')
                                    }
                                    disabled={!takesArguments[command]}
                                />
                            </div>

                            <Button
                                type="submit"
                                disabled={processing}
                                className={`w-full sm:w-auto ${CONTROL_HEIGHT}`}
                            >
                                {processing ? <Spinner /> : <Play />}
                                {processing ? __('Running…') : __('Run')}
                            </Button>
                        </div>

                        <InputError
                            className="mt-2"
                            message={errors.command ?? errors.arguments}
                        />

                        {result && <CommandOutput result={result} />}
                    </>
                )}
            </Form>
        </section>
    );
}

function LatestUsers({ users, total }: { users: AdminUser[]; total: number }) {
    return (
        <section className="space-y-4">
            <div className="flex items-center justify-between gap-4">
                <HeadingSmall
                    title={__('Latest sign-ups')}
                    description={__(':count users in total.', {
                        count: total.toLocaleString(),
                    })}
                />
                <Button
                    variant="outline"
                    size="sm"
                    className="h-11 sm:h-8"
                    asChild
                >
                    <Link href={usersIndex().url}>
                        {__('See all')}
                        <ArrowRight />
                    </Link>
                </Button>
            </div>

            <AdminUserTable users={users} />
        </section>
    );
}

/**
 * Internal tools, behind the ADMIN_EMAIL account. Read-only on the user side;
 * it writes only whichever curated Artisan command was picked and the status
 * of integration requests.
 */
export default function Admin({
    commands,
    signupChart,
    latestUsers,
    userCount,
    integrationRequests,
    result,
}: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Admin')} />

            <div className="flex flex-col gap-8 p-6">
                <PageHeader
                    title={__('Admin')}
                    description={__(
                        'Internal tools. Only the ADMIN_EMAIL account can open this page.',
                    )}
                />

                <div className="space-y-8">
                    <CommandRunner commands={commands} result={result} />
                    <IntegrationRequestList requests={integrationRequests} />
                    <SignupChart points={signupChart} />
                    <LatestUsers users={latestUsers} total={userCount} />
                </div>
            </div>
        </AppLayout>
    );
}
