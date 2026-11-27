import { update } from '@/actions/App/Http/Controllers/Admin/IntegrationRequestController';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { SettingsTable } from '@/components/shared/settings-table';
import { Badge, type badgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Form } from '@inertiajs/react';
import {
    ColumnDef,
    getCoreRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { type VariantProps } from 'class-variance-authority';
import { Check, Pencil } from 'lucide-react';
import { useMemo, useState } from 'react';

export type IntegrationRequestStatus =
    | 'pending'
    | 'approved'
    | 'in_progress'
    | 'rejected'
    | 'not_doable'
    | 'done';

export type AdminIntegrationRequest = {
    id: string;
    name: string;
    url: string;
    status: IntegrationRequestStatus;
    comment: string | null;
    votes_count: number;
    submitted_by: string | null;
    created_at: string | null;
};

const STATUSES: IntegrationRequestStatus[] = [
    'pending',
    'approved',
    'in_progress',
    'rejected',
    'not_doable',
    'done',
];

function statusLabel(status: IntegrationRequestStatus): string {
    const labels: Record<IntegrationRequestStatus, string> = {
        pending: __('Pending review'),
        approved: __('Approved'),
        in_progress: __('In progress'),
        rejected: __('Rejected'),
        not_doable: __('Not doable'),
        done: __('Done'),
    };

    return labels[status];
}

/** Mirrors IntegrationRequestStatus::keepsComment() on the server. */
function keepsComment(status: IntegrationRequestStatus): boolean {
    return status === 'in_progress' || status === 'not_doable';
}

/**
 * Pending asks for the admin's action (warning), done is the healthy end state
 * (success), the closed ones fade out and the rest are neutral.
 */
const STATUS_BADGE_VARIANT: Record<
    IntegrationRequestStatus,
    VariantProps<typeof badgeVariants>['variant']
> = {
    pending: 'warning',
    approved: 'secondary',
    in_progress: 'secondary',
    rejected: 'outline',
    not_doable: 'outline',
    done: 'success',
};

/**
 * The one-click approve only fits from `sm` up; on a phone the row keeps just
 * the "Change status" button, whose dialog can approve too.
 */
const APPROVE_FORM_CLASS = 'hidden sm:block';

function ApproveButton({ request }: { request: AdminIntegrationRequest }) {
    return (
        <Form
            {...update.form(request.id)}
            options={{ preserveScroll: true, preserveState: true }}
            className={APPROVE_FORM_CLASS}
        >
            {({ processing }) => (
                <>
                    <input type="hidden" name="status" value="approved" />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={processing}
                    >
                        {processing ? <Spinner /> : <Check />}
                        {__('Approve')}
                    </Button>
                </>
            )}
        </Form>
    );
}

function ChangeStatusDialog({ request }: { request: AdminIntegrationRequest }) {
    const [open, setOpen] = useState(false);
    const [status, setStatus] = useState(request.status);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    setStatus(request.status);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    size="icon-sm"
                    variant="ghost"
                    className="size-11 sm:size-8"
                    aria-label={__('Change status')}
                >
                    <Pencil className="size-3.5" />
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{request.name}</DialogTitle>
                <DialogDescription className="break-all">
                    {request.url}
                </DialogDescription>

                <Form
                    {...update.form(request.id)}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={`status-${request.id}`}>
                                    {__('Status')}
                                </Label>
                                <Select
                                    name="status"
                                    value={status}
                                    onValueChange={(value) =>
                                        setStatus(
                                            value as IntegrationRequestStatus,
                                        )
                                    }
                                >
                                    <SelectTrigger id={`status-${request.id}`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {STATUSES.map((option) => (
                                            <SelectItem
                                                key={option}
                                                value={option}
                                            >
                                                {statusLabel(option)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.status} />
                            </div>

                            {keepsComment(status) && (
                                <div className="grid gap-2">
                                    <Label htmlFor={`comment-${request.id}`}>
                                        {__('Comment')}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            {status === 'not_doable'
                                                ? __('(shown to users)')
                                                : __(
                                                      '(optional, shown to users)',
                                                  )}
                                        </span>
                                    </Label>
                                    <Textarea
                                        id={`comment-${request.id}`}
                                        name="comment"
                                        defaultValue={request.comment ?? ''}
                                        required={status === 'not_doable'}
                                        maxLength={1000}
                                    />
                                    <InputError message={errors.comment} />
                                </div>
                            )}

                            {!keepsComment(status) && request.comment && (
                                <p className="text-sm text-muted-foreground">
                                    {__(
                                        'This status has no comment: the current one will be removed.',
                                    )}
                                </p>
                            )}

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        {__('Cancel')}
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {__('Save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** Hidden on a phone, where only the request, its status and actions fit. */
const WIDE_ONLY = 'hidden sm:table-cell';

const columns: ColumnDef<AdminIntegrationRequest>[] = [
    {
        accessorKey: 'name',
        header: () => __('Request'),
        cell: ({ row }) => (
            <div className="min-w-0">
                <a
                    href={row.original.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium hover:underline"
                >
                    {row.original.name}
                </a>
                <div className="max-w-32 truncate font-mono text-xs text-muted-foreground sm:max-w-none">
                    {row.original.submitted_by ?? '—'}
                </div>
            </div>
        ),
    },
    {
        id: 'votes_count',
        header: () => __('Votes'),
        meta: { cellClassName: WIDE_ONLY },
        cell: ({ row }) => (
            <span className="text-sm tabular-nums">
                {row.original.votes_count}
            </span>
        ),
    },
    {
        id: 'created_at',
        header: () => __('Submitted'),
        meta: { cellClassName: WIDE_ONLY },
        cell: ({ row }) => (
            <span className="text-sm whitespace-nowrap text-muted-foreground">
                {row.original.created_at
                    ? formatDateMedium(row.original.created_at)
                    : '—'}
            </span>
        ),
    },
    {
        id: 'status',
        header: () => __('Status'),
        cell: ({ row }) => (
            <div className="flex max-w-40 flex-col items-start gap-1 sm:max-w-64">
                <Badge variant={STATUS_BADGE_VARIANT[row.original.status]}>
                    {statusLabel(row.original.status)}
                </Badge>
                {row.original.comment && (
                    <span className="line-clamp-2 text-xs whitespace-normal text-muted-foreground">
                        {row.original.comment}
                    </span>
                )}
            </div>
        ),
    },
    {
        id: 'actions',
        header: () => <span className="sr-only">{__('Actions')}</span>,
        meta: { cellClassName: 'text-right' },
        cell: ({ row }) => (
            <div className="flex items-center justify-end gap-1">
                {row.original.status === 'pending' && (
                    <ApproveButton request={row.original} />
                )}
                <ChangeStatusDialog request={row.original} />
            </div>
        ),
    },
];

type Filter = IntegrationRequestStatus | 'all';

/**
 * The web form of `integration-requests:review`: every request on the
 * community board, the pending ones first, each one a click from approved.
 */
export function IntegrationRequestList({
    requests,
}: {
    requests: AdminIntegrationRequest[];
}) {
    const pendingCount = requests.filter(
        (request) => request.status === 'pending',
    ).length;
    const [filter, setFilter] = useState<Filter>(
        pendingCount > 0 ? 'pending' : 'all',
    );
    // Memoised: a fresh array on every render makes the table re-render forever.
    const visible = useMemo(
        () =>
            filter === 'all'
                ? requests
                : requests.filter((request) => request.status === filter),
        [requests, filter],
    );

    const table = useReactTable({
        data: visible,
        columns,
        getRowId: (request) => request.id,
        getCoreRowModel: getCoreRowModel(),
    });

    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-4">
                <HeadingSmall
                    title={__('Integration requests')}
                    description={__('Pending review: :count', {
                        count: pendingCount,
                    })}
                />
                <Select
                    value={filter}
                    onValueChange={(value) => setFilter(value as Filter)}
                >
                    <SelectTrigger
                        className="h-11 w-full sm:h-9 sm:w-48"
                        aria-label={__('Filter by status')}
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">{__('All')}</SelectItem>
                        {STATUSES.map((status) => (
                            <SelectItem key={status} value={status}>
                                {statusLabel(status)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <SettingsTable
                table={table}
                emptyMessage={
                    filter === 'all'
                        ? __('No integration requests found.')
                        : __('No requests with this status.')
                }
            />
        </section>
    );
}
