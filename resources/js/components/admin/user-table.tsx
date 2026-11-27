import { SettingsTable } from '@/components/shared/settings-table';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import {
    ColumnDef,
    getCoreRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { formatDistanceToNowStrict } from 'date-fns';

export type AdminUser = {
    id: string;
    name: string;
    email: string;
    locale: string | null;
    currency_code: string | null;
    created_at: string | null;
    last_active_at: string | null;
};

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

/** Users as the admin pages list them: who, where from, when they joined and were last here. */
export function AdminUserTable({ users }: { users: AdminUser[] }) {
    const table = useReactTable({
        data: users,
        columns: userColumns,
        getRowId: (user) => user.id,
        getCoreRowModel: getCoreRowModel(),
    });

    return <SettingsTable table={table} emptyMessage={__('No users found.')} />;
}
