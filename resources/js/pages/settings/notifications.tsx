import {
    index as notificationsIndex,
    update,
    updateBudget,
} from '@/actions/App/Http/Controllers/Settings/NotificationPreferenceController';
import HeadingSmall from '@/components/heading-small';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Notifications',
        href: notificationsIndex().url,
    },
];

type BudgetToggleKey =
    | 'notify_on_new_transaction'
    | 'notify_on_close_to_limit'
    | 'notify_on_over_limit';

interface BudgetRow {
    id: UUID;
    name: string;
    notify_on_new_transaction: boolean;
    notify_on_close_to_limit: boolean;
    notify_on_over_limit: boolean;
}

interface Props {
    notifyAchievements: boolean | null;
    notifyOnBankTransactionsSynced: boolean;
    notifyOnInactiveNoBank: boolean;
    notifyMonthlySummary: boolean;
    budgetDefaults: Record<BudgetToggleKey, boolean>;
    budgets: BudgetRow[];
}

// `key` is the column on the budget/default; `defaultKey` is the wire key the
// user-settings endpoint expects for the "Default (new budgets)" row.
const BUDGET_COLUMNS: {
    key: BudgetToggleKey;
    defaultKey: string;
    label: string;
}[] = [
    {
        key: 'notify_on_new_transaction',
        defaultKey: 'budget_new_transaction',
        label: 'New transaction',
    },
    {
        key: 'notify_on_close_to_limit',
        defaultKey: 'budget_close_to_limit',
        label: 'Close to limit',
    },
    {
        key: 'notify_on_over_limit',
        defaultKey: 'budget_over_limit',
        label: 'Over limit',
    },
];

const patchOptions = { preserveScroll: true, preserveState: true } as const;

export default function Notifications({
    notifyAchievements,
    notifyOnBankTransactionsSynced,
    notifyOnInactiveNoBank,
    notifyMonthlySummary,
    budgetDefaults,
    budgets,
}: Props) {
    const emailToggles = [
        {
            id: 'notify-monthly-summary',
            preferenceKey: 'monthly_summary',
            checked: notifyMonthlySummary,
            label: __('Monthly summary'),
            description: __(
                'A report of the month just closed, with what changed and a few things worth five minutes. Turning it off also stops the reminder that goes with it.',
            ),
        },
        // Null while the medals are switched off: nothing would send this email.
        ...(notifyAchievements === null
            ? []
            : [
                  {
                      id: 'notify-achievements',
                      preferenceKey: 'achievements',
                      checked: notifyAchievements,
                      label: __('Achievements'),
                      description: __(
                          'One email a day when you unlock something, however many you unlock. They always show up in the app either way.',
                      ),
                  },
              ]),
        {
            id: 'notify-on-bank-transactions-synced',
            preferenceKey: 'bank_transactions_synced',
            checked: notifyOnBankTransactionsSynced,
            label: __('New transactions from connected banks'),
            description: __(
                'Receive an email when new transactions are imported from your connected banks. Sent at most once a day.',
            ),
        },
        {
            id: 'notify-on-inactive-no-bank',
            preferenceKey: 'inactive_no_bank',
            checked: notifyOnInactiveNoBank,
            label: __('Reminders when no bank is connected'),
            description: __(
                "Receive an email if you haven't visited in a week and no bank is connected.",
            ),
        },
    ];

    const patchPreference = (key: string, checked: boolean) => {
        router.patch(
            update().url,
            { notifications: { [key]: checked } },
            patchOptions,
        );
    };

    const patchBudget = (id: UUID, key: BudgetToggleKey, checked: boolean) => {
        router.patch(updateBudget(id).url, { [key]: checked }, patchOptions);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Notifications')} />

            <SettingsLayout>
                <div className="space-y-8">
                    <HeadingSmall
                        title={__('Email notifications')}
                        description={__(
                            'Manage the automatic notifications you receive',
                        )}
                    />

                    <div className="space-y-4">
                        {emailToggles.map((toggle) => (
                            <div
                                key={toggle.id}
                                className="flex items-start gap-3"
                            >
                                <Checkbox
                                    id={toggle.id}
                                    defaultChecked={toggle.checked}
                                    onCheckedChange={(checked) =>
                                        patchPreference(
                                            toggle.preferenceKey,
                                            checked === true,
                                        )
                                    }
                                    className="mt-0.5"
                                />
                                <div className="grid gap-1">
                                    <Label htmlFor={toggle.id}>
                                        {toggle.label}
                                    </Label>
                                    <p className="text-sm text-muted-foreground">
                                        {toggle.description}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="space-y-4">
                        <div>
                            <h4 className="text-sm font-medium">
                                {__('Budgets')}
                            </h4>
                            <p className="text-sm text-muted-foreground">
                                {__(
                                    'Choose which budget emails you receive. The default row applies to newly created budgets.',
                                )}
                            </p>
                        </div>

                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{__('Budget')}</TableHead>
                                        {BUDGET_COLUMNS.map((column) => (
                                            <TableHead
                                                key={column.key}
                                                className="text-center"
                                            >
                                                {__(column.label)}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow>
                                        <TableCell className="font-medium text-muted-foreground">
                                            {__('Default (new budgets)')}
                                        </TableCell>
                                        {BUDGET_COLUMNS.map((column) => (
                                            <TableCell
                                                key={column.key}
                                                className="text-center"
                                            >
                                                <Checkbox
                                                    aria-label={`${__('Default (new budgets)')} – ${__(column.label)}`}
                                                    defaultChecked={
                                                        budgetDefaults[
                                                            column.key
                                                        ]
                                                    }
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        patchPreference(
                                                            column.defaultKey,
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                            </TableCell>
                                        ))}
                                    </TableRow>

                                    {budgets.map((budget) => (
                                        <TableRow key={budget.id}>
                                            <TableCell className="font-medium">
                                                {budget.name}
                                            </TableCell>
                                            {BUDGET_COLUMNS.map((column) => (
                                                <TableCell
                                                    key={column.key}
                                                    className="text-center"
                                                >
                                                    <Checkbox
                                                        aria-label={`${budget.name} – ${__(column.label)}`}
                                                        defaultChecked={
                                                            budget[column.key]
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            patchBudget(
                                                                budget.id,
                                                                column.key,
                                                                checked ===
                                                                    true,
                                                            )
                                                        }
                                                    />
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    ))}

                                    {budgets.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={
                                                    BUDGET_COLUMNS.length + 1
                                                }
                                                className="text-center text-sm text-muted-foreground"
                                            >
                                                {__('You have no budgets yet.')}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
