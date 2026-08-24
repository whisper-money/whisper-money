import { index } from '@/actions/App/Http/Controllers/BudgetController';
import { show } from '@/actions/App/Http/Controllers/SavingsGoalController';
import HeadingSmall from '@/components/heading-small';
import { MobileBackButton } from '@/components/mobile-back-button';
import { DeleteSavingsGoalDialog } from '@/components/savings-goals/delete-savings-goal-dialog';
import { EditSavingsGoalDialog } from '@/components/savings-goals/edit-savings-goal-dialog';
import { LinkTransactionsDialog } from '@/components/savings-goals/link-transactions-dialog';
import { SavingsGoalProgressChart } from '@/components/savings-goals/savings-goal-progress-chart';
import { LabelBadge } from '@/components/shared/label-combobox';
import { TransactionList } from '@/components/transactions/transaction-list';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Progress } from '@/components/ui/progress';
import { useLocale } from '@/hooks/use-locale';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import { Account, Bank } from '@/types/account';
import { Category } from '@/types/category';
import { Label } from '@/types/label';
import {
    getSavingsGoalStatusColor,
    getSavingsGoalStatusLabel,
    SavingsGoal,
    SavingsGoalStats,
} from '@/types/savings-goal';
import { ServerTransaction } from '@/types/transaction';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

interface Props {
    savingsGoal: SavingsGoal;
    transactions: ServerTransaction[];
    stats: SavingsGoalStats;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    currencyCode: string;
    recentTransactions?: ServerTransaction[];
}

export default function SavingsGoalShow({
    savingsGoal,
    transactions,
    stats,
    categories,
    accounts,
    banks,
    labels,
    currencyCode,
    recentTransactions,
}: Props) {
    const locale = useLocale();
    const [linkOpen, setLinkOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    const statusColor = stats.status
        ? getSavingsGoalStatusColor(stats.status)
        : 'text-muted-foreground';

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Budgets',
            href: index().url,
        },
        {
            title: savingsGoal.name,
            href: show({ savingsGoal: savingsGoal.id }).url,
        },
    ];

    return (
        <AppSidebarLayout
            breadcrumbs={breadcrumbs}
            mobileLeading={<MobileBackButton href={index().url} />}
        >
            <Head title={savingsGoal.name} />

            <div className="space-y-6 p-6">
                <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <HeadingSmall
                        title={savingsGoal.name}
                        description={
                            <div className="flex flex-row flex-wrap items-center gap-1 text-sm">
                                {savingsGoal.label && (
                                    <LabelBadge label={savingsGoal.label} />
                                )}
                                {savingsGoal.target_date && (
                                    <span className="opacity-50">
                                        {__('Target :date', {
                                            date: formatDate(
                                                savingsGoal.target_date,
                                                'MMM d, yyyy',
                                                locale,
                                            ),
                                        })}
                                    </span>
                                )}
                            </div>
                        }
                    />

                    <div className="flex items-center gap-2">
                        <Button onClick={() => setLinkOpen(true)}>
                            {__('Link transactions')}
                        </Button>

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    aria-label={__('More options')}
                                >
                                    <ChevronDown className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    onClick={() => setEditOpen(true)}
                                >
                                    {__('Edit goal')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onClick={() => setDeleteOpen(true)}
                                    variant="destructive"
                                >
                                    {__('Delete goal')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center justify-between">
                            <span>
                                <AmountDisplay
                                    amountInCents={stats.saved}
                                    currencyCode={currencyCode}
                                />{' '}
                                <span className="text-base font-normal text-muted-foreground">
                                    {__('of')}{' '}
                                    <AmountDisplay
                                        amountInCents={stats.target}
                                        currencyCode={currencyCode}
                                    />
                                </span>
                            </span>
                            {stats.status && (
                                <Badge
                                    variant="outline"
                                    className={statusColor}
                                >
                                    {getSavingsGoalStatusLabel(stats.status)}
                                </Badge>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Progress
                            value={Math.min(Math.max(stats.percentage, 0), 100)}
                            className="h-2"
                        />
                        <div className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
                            <div>
                                <p className="text-muted-foreground">
                                    {__('Progress')}
                                </p>
                                <p className="font-medium">
                                    {Math.max(0, Math.round(stats.percentage))}%
                                </p>
                            </div>
                            {savingsGoal.target_date &&
                                stats.estimated_date && (
                                    <div>
                                        <p className="text-muted-foreground">
                                            {__('Estimated completion')}
                                        </p>
                                        <p className="font-medium">
                                            {formatDate(
                                                stats.estimated_date,
                                                'MMM d, yyyy',
                                                locale,
                                            )}
                                        </p>
                                    </div>
                                )}
                            {stats.required_per_month !== null &&
                                stats.required_per_month > 0 && (
                                    <div>
                                        <p className="text-muted-foreground">
                                            {__('Needed per month')}
                                        </p>
                                        <p className="font-medium">
                                            <AmountDisplay
                                                amountInCents={
                                                    stats.required_per_month
                                                }
                                                currencyCode={currencyCode}
                                            />
                                        </p>
                                    </div>
                                )}
                        </div>
                    </CardContent>
                </Card>

                <SavingsGoalProgressChart
                    savingsGoal={savingsGoal}
                    stats={stats}
                    transactions={transactions}
                    currencyCode={currencyCode}
                />

                <TransactionList
                    categories={categories}
                    accounts={accounts}
                    banks={banks}
                    labels={labels}
                    transactions={transactions}
                    pageSize={10}
                    showActionsMenu={false}
                    maxHeight={600}
                />
            </div>

            <LinkTransactionsDialog
                savingsGoal={savingsGoal}
                transactions={transactions}
                recentTransactions={recentTransactions}
                currencyCode={currencyCode}
                open={linkOpen}
                onOpenChange={setLinkOpen}
            />

            <EditSavingsGoalDialog
                savingsGoal={savingsGoal}
                currencyCode={currencyCode}
                open={editOpen}
                onOpenChange={setEditOpen}
            />

            <DeleteSavingsGoalDialog
                savingsGoal={savingsGoal}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                redirectTo={index().url}
            />
        </AppSidebarLayout>
    );
}
