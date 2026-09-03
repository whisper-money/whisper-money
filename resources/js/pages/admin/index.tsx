import { index, run } from '@/actions/App/Http/Controllers/AdminController';
import Heading from '@/components/heading';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SettingsTable } from '@/components/shared/settings-table';
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
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Form, Head, Link } from '@inertiajs/react';
import {
    ColumnDef,
    getCoreRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { formatDistanceToNowStrict } from 'date-fns';
import { Check, Copy, Play } from 'lucide-react';
import { useState } from 'react';

type AdminUser = {
    id: string;
    name: string;
    email: string;
    locale: string | null;
    currency_code: string | null;
    created_at: string | null;
    last_active_at: string | null;
};

type CommandResult = {
    command: string;
    exit_code: number;
    output: string;
    duration_ms: number;
    ran_at: string;
};

type Paginated<T> = {
    data: T[];
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

/** Group label to its command lines, each mapped to whether it takes arguments. */
type CommandGroups = Record<string, Record<string, boolean>>;

interface Props {
    commands: CommandGroups;
    users: Paginated<AdminUser>;
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
                    variant={result.exit_code === 0 ? 'outline' : 'destructive'}
                    className={
                        result.exit_code === 0
                            ? 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                            : undefined
                    }
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
                            <div className="space-y-2 sm:w-80">
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

                            <div className="space-y-2 sm:max-w-md sm:flex-1">
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

/** Hidden on a phone, where only who they are and when they were last here fit. */
const WIDE_ONLY = 'hidden sm:table-cell';

const userColumns: ColumnDef<AdminUser>[] = [
    {
        accessorKey: 'name',
        header: () => __('User'),
        cell: ({ row }) => (
            <div className="min-w-0">
                <div className="font-medium">{row.original.name}</div>
                <div className="truncate font-mono text-xs text-muted-foreground">
                    {row.original.email}
                </div>
            </div>
        ),
    },
    {
        id: 'locale',
        header: () => __('Locale'),
        meta: { cellClassName: WIDE_ONLY },
        cell: ({ row }) => (
            <span className="text-sm text-muted-foreground">
                {row.original.locale ?? '—'} ·{' '}
                {row.original.currency_code ?? '—'}
            </span>
        ),
    },
    {
        id: 'created_at',
        header: () => __('Signed up'),
        meta: { cellClassName: WIDE_ONLY },
        cell: ({ row }) => (
            <span className="text-sm text-muted-foreground">
                {row.original.created_at
                    ? formatDateMedium(row.original.created_at)
                    : '—'}
            </span>
        ),
    },
    {
        id: 'last_active_at',
        header: () => __('Last active'),
        meta: { cellClassName: 'text-right sm:text-left' },
        cell: ({ row }) => (
            <span className="text-sm whitespace-nowrap text-muted-foreground">
                {row.original.last_active_at
                    ? formatDistanceToNowStrict(
                          new Date(row.original.last_active_at),
                          { addSuffix: true },
                      )
                    : __('Never')}
            </span>
        ),
    },
];

/** 44px tap targets on a phone, `size="sm"` from `sm` up. */
const PAGER_HEIGHT = 'h-11 sm:h-8';

/** One end of the pager: a link while there is a page there, a dead button when not. */
function PageLink({ href, label }: { href: string | null; label: string }) {
    if (!href) {
        return (
            <Button
                variant="outline"
                size="sm"
                className={PAGER_HEIGHT}
                disabled
            >
                {label}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" className={PAGER_HEIGHT} asChild>
            <Link href={href} preserveScroll>
                {label}
            </Link>
        </Button>
    );
}

function UserList({ users }: { users: Paginated<AdminUser> }) {
    const table = useReactTable({
        data: users.data,
        columns: userColumns,
        getRowId: (user) => user.id,
        getCoreRowModel: getCoreRowModel(),
    });

    return (
        <section className="space-y-4">
            <div className="flex items-baseline justify-between gap-4">
                <HeadingSmall title={__('Users')} />
                <span className="text-sm text-muted-foreground">
                    {users.total.toLocaleString()}
                </span>
            </div>

            <SettingsTable table={table} emptyMessage={__('No users found.')} />

            <div className="flex items-center justify-between gap-4">
                <span className="text-sm text-muted-foreground">
                    {__('Showing :from–:to of :total', {
                        from: users.from ?? 0,
                        to: users.to ?? 0,
                        total: users.total.toLocaleString(),
                    })}
                </span>
                <div className="flex gap-2">
                    <PageLink
                        href={users.prev_page_url}
                        label={__('Previous')}
                    />
                    <PageLink href={users.next_page_url} label={__('Next')} />
                </div>
            </div>
        </section>
    );
}

/**
 * Internal tools, behind the ADMIN_EMAIL account. Read-only on the user side;
 * the only thing it writes is whichever curated Artisan command was picked.
 */
export default function Admin({ commands, users, result }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Admin')} />

            <div className="p-6">
                <Heading
                    title={__('Admin')}
                    description={__(
                        'Internal tools. Only the ADMIN_EMAIL account can open this page.',
                    )}
                />

                <div className="space-y-8">
                    <CommandRunner commands={commands} result={result} />
                    <UserList users={users} />
                </div>
            </div>
        </AppLayout>
    );
}
