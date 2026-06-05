import { index, show } from '@/actions/App/Http/Controllers/AccountController';
import { update as updateLoanDetail } from '@/actions/App/Http/Controllers/LoanDetailController';
import { update as updateRealEstateDetail } from '@/actions/App/Http/Controllers/RealEstateDetailController';
import {
    AccountBalanceChart,
    type BalanceDataPoint,
    type ChartComputedData,
} from '@/components/accounts/account-balance-chart';
import { BalancesModal } from '@/components/accounts/balances-modal';
import { EditAccountDialog } from '@/components/accounts/edit-account-dialog';
import { EditLoanDetailDialog } from '@/components/accounts/edit-loan-detail-dialog';
import { ImportBalancesDrawer } from '@/components/accounts/import-balances-drawer';
import { UpdateBalanceDialog } from '@/components/accounts/update-balance-dialog';
import { BankLogo } from '@/components/bank-logo';
import { AmountTrendIndicator } from '@/components/dashboard/amount-trend-indicator';
import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { MobileBackButton } from '@/components/mobile-back-button';
import { EditTransactionDialog } from '@/components/transactions/edit-transaction-dialog';
import { TransactionList } from '@/components/transactions/transaction-list';
import { AmountDisplay } from '@/components/ui/amount-display';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { BreadcrumbItem } from '@/types';
import {
    Account,
    AREA_UNITS,
    Bank,
    formatAccountType,
    formatAreaUnit,
    formatPropertyType,
    isTransactionalAccount,
    PROPERTY_TYPES,
    type AreaUnit,
    type LoanDetail,
    type PropertyType,
    type RealEstateDetail,
} from '@/types/account';
import { AutomationRule } from '@/types/automation-rule';
import { Category } from '@/types/category';
import { Label as LabelType } from '@/types/label';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, Pencil, Plus } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { Line, LineChart, ResponsiveContainer, Tooltip } from 'recharts';
import { toast } from 'sonner';

interface AccountWithDetails extends Account {
    real_estate_detail?: RealEstateDetail;
    available_loan_accounts?: Account[];
    loan_detail?: LoanDetail;
    linked_loan_account?: Account;
}

interface Props {
    account: AccountWithDetails;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels?: LabelType[];
    automationRules?: AutomationRule[];
}

export default function AccountShow({
    account,
    categories,
    accounts,
    banks,
    labels = [],
    automationRules = [],
}: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [updateBalanceOpen, setUpdateBalanceOpen] = useState(false);
    const [updateLoanBalanceOpen, setUpdateLoanBalanceOpen] = useState(false);
    const [importBalancesOpen, setImportBalancesOpen] = useState(false);
    const [balancesOpen, setBalancesOpen] = useState(false);
    const [chartRefreshKey, setChartRefreshKey] = useState(0);
    const [editingDetails, setEditingDetails] = useState(false);
    const [editingLoanDetails, setEditingLoanDetails] = useState(false);
    const [editLoanDialogOpen, setEditLoanDialogOpen] = useState(false);
    const [createTransactionOpen, setCreateTransactionOpen] = useState(false);
    const [transactionRefreshKey, setTransactionRefreshKey] = useState(0);
    const [chartComputedData, setChartComputedData] =
        useState<ChartComputedData | null>(null);
    const { isKeySet } = useEncryptionKey();

    const handleChartDataLoaded = useCallback((data: ChartComputedData) => {
        setChartComputedData(data);
    }, []);

    function handleBalanceUpdated() {
        setChartRefreshKey((prev) => prev + 1);
    }

    function handleAddTransaction() {
        if (!isKeySet) {
            toast.error(
                __('Please unlock your encryption key to add transactions'),
            );
            return;
        }

        setCreateTransactionOpen(true);
    }

    function handleTransactionCreated() {
        setTransactionRefreshKey((prev) => prev + 1);
        handleBalanceUpdated();
    }

    const isConnected = !!account.banking_connection_id;
    const isLoan = account.type === 'loan';
    const isRealEstate = account.type === 'real_estate';
    const realEstateDetail = account.real_estate_detail;
    const loanDetail = account.loan_detail;
    const linkedLoanAccount = account.linked_loan_account;
    const hasLinkedLoan = isRealEstate && !!linkedLoanAccount;
    const canCreateTransaction =
        !isConnected && isTransactionalAccount(account);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Accounts',
            href: index().url,
        },
        {
            title: account.name,

            href: show.url(account.id),
        },
    ];

    const updateBalanceLabel = isLoan
        ? __('Update owed amount')
        : isRealEstate
          ? __('Update market value')
          : __('Update balance');

    const importBalancesLabel = isLoan
        ? __('Import owed amounts')
        : isRealEstate
          ? __('Import market values')
          : __('Import balances');

    const seeBalancesLabel = isLoan
        ? __('See owed amounts')
        : isRealEstate
          ? __('See market values')
          : __('See balances');

    return (
        <AppSidebarLayout
            breadcrumbs={breadcrumbs}
            mobileLeading={<MobileBackButton href={index().url} />}
        >
            <Head title={__('Account Details')} />

            <div className="space-y-6 p-6">
                <div className="sm flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                    <div className="flex items-center gap-4 pl-1">
                        {account.bank && (
                            <BankLogo
                                src={account.bank.logo}
                                name={account.bank.name}
                                className="size-12"
                                fallback="letter"
                            />
                        )}
                        <HeadingSmall
                            title={account.name}
                            description={`${account.bank ? `${account.bank.name} · ` : ''}${formatAccountType(account.type)}`}
                        />
                    </div>

                    {isConnected && !hasLinkedLoan ? (
                        <Button
                            variant="outline"
                            onClick={() => setEditOpen(true)}
                        >
                            {__('Edit account')}
                        </Button>
                    ) : isConnected && hasLinkedLoan ? (
                        <ButtonGroup>
                            <Button
                                variant="outline"
                                onClick={() => setEditOpen(true)}
                            >
                                {__('Edit account')}
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => setEditLoanDialogOpen(true)}
                            >
                                {__('Edit loan details')}
                            </Button>
                        </ButtonGroup>
                    ) : !isConnected && hasLinkedLoan ? (
                        <ButtonGroup>
                            <ButtonGroup>
                                <Button
                                    variant="outline"
                                    onClick={() => setUpdateBalanceOpen(true)}
                                >
                                    {__('Update market value')}
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setUpdateLoanBalanceOpen(true)
                                    }
                                >
                                    {__('Update owed amount')}
                                </Button>
                            </ButtonGroup>
                            <ButtonGroup>
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
                                            onClick={() =>
                                                setBalancesOpen(true)
                                            }
                                        >
                                            {__('See market values')}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onClick={() =>
                                                setImportBalancesOpen(true)
                                            }
                                        >
                                            {__('Import market values')}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onClick={() => setEditOpen(true)}
                                        >
                                            {__('Edit account')}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onClick={() =>
                                                setEditLoanDialogOpen(true)
                                            }
                                        >
                                            {__('Edit loan details')}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </ButtonGroup>
                        </ButtonGroup>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {canCreateTransaction && (
                                <Button
                                    variant="outline"
                                    onClick={handleAddTransaction}
                                >
                                    <Plus className="h-4 w-4" />
                                    {__('Add transaction')}
                                </Button>
                            )}
                            <ButtonGroup>
                                <Button
                                    variant="outline"
                                    onClick={() => setUpdateBalanceOpen(true)}
                                >
                                    {updateBalanceLabel}
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setImportBalancesOpen(true)}
                                >
                                    {importBalancesLabel}
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
                                            onClick={() =>
                                                setBalancesOpen(true)
                                            }
                                        >
                                            {seeBalancesLabel}
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onClick={() => setEditOpen(true)}
                                        >
                                            {__('Edit account')}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </ButtonGroup>
                        </div>
                    )}
                </div>

                <AccountBalanceChart
                    account={account}
                    refreshKey={chartRefreshKey}
                    onBalanceClick={
                        isConnected
                            ? undefined
                            : () => setUpdateBalanceOpen(true)
                    }
                    onDataLoaded={handleChartDataLoaded}
                />

                {isRealEstate &&
                    realEstateDetail &&
                    chartComputedData?.hasMortgageData && (
                        <EquitySummaryCards
                            chartData={chartComputedData.chartData}
                            currentBalance={chartComputedData.currentBalance}
                            currentMortgageBalance={
                                chartComputedData.currentMortgageBalance
                            }
                            currencyCode={chartComputedData.currencyCode}
                        />
                    )}

                {isRealEstate && realEstateDetail && (
                    <PropertyDetailsCard
                        detail={realEstateDetail}
                        account={account}
                        availableLoanAccounts={
                            account.available_loan_accounts ?? []
                        }
                        isEditing={editingDetails}
                        onEditToggle={setEditingDetails}
                    />
                )}

                {isRealEstate && hasLinkedLoan && (
                    <LoanDetailsCard
                        detail={loanDetail ?? null}
                        account={account}
                        loanAccountId={linkedLoanAccount.id}
                        isEditing={editingLoanDetails}
                        onEditToggle={setEditingLoanDetails}
                        onEditDialogOpen={() => setEditLoanDialogOpen(true)}
                    />
                )}

                {isLoan && (
                    <LoanDetailsCard
                        detail={loanDetail ?? null}
                        account={account}
                        isEditing={editingLoanDetails}
                        onEditToggle={setEditingLoanDetails}
                    />
                )}

                {isTransactionalAccount(account) && (
                    <TransactionList
                        key={transactionRefreshKey}
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        labels={labels}
                        automationRules={automationRules}
                        accountId={account.id}
                        pageSize={50}
                        hideAccountFilter={true}
                        showActionsMenu={false}
                        maxHeight={600}
                        hideColumns={['bank', 'account']}
                        onBalanceUpdated={() =>
                            setChartRefreshKey((key) => key + 1)
                        }
                    />
                )}
            </div>

            <EditAccountDialog
                account={account}
                open={editOpen}
                onOpenChange={setEditOpen}
                redirectTo={show.url(account.id)}
                deleteRedirectTo={isConnected ? undefined : index().url}
            />

            <UpdateBalanceDialog
                account={account}
                open={updateBalanceOpen}
                onOpenChange={setUpdateBalanceOpen}
                onSuccess={handleBalanceUpdated}
            />

            <EditTransactionDialog
                transaction={null}
                categories={categories}
                accounts={accounts}
                banks={banks}
                labels={labels}
                automationRules={automationRules}
                open={createTransactionOpen}
                onOpenChange={setCreateTransactionOpen}
                onSuccess={handleTransactionCreated}
                mode="create"
                initialAccountId={account.id}
            />

            <BalancesModal
                account={account}
                open={balancesOpen}
                onOpenChange={setBalancesOpen}
                onBalanceChange={handleBalanceUpdated}
            />

            <ImportBalancesDrawer
                open={importBalancesOpen}
                onOpenChange={setImportBalancesOpen}
                accounts={accounts}
                account={account}
                accountId={account.id}
                onSuccess={handleBalanceUpdated}
            />

            {hasLinkedLoan && (
                <>
                    <EditLoanDetailDialog
                        loanAccountId={linkedLoanAccount.id}
                        currencyCode={
                            linkedLoanAccount.currency_code ??
                            account.currency_code
                        }
                        detail={loanDetail ?? null}
                        open={editLoanDialogOpen}
                        onOpenChange={setEditLoanDialogOpen}
                    />
                    <UpdateBalanceDialog
                        account={linkedLoanAccount}
                        open={updateLoanBalanceOpen}
                        onOpenChange={setUpdateLoanBalanceOpen}
                        onSuccess={handleBalanceUpdated}
                    />
                </>
            )}
        </AppSidebarLayout>
    );
}

interface EquitySummaryCardsProps {
    chartData: BalanceDataPoint[];
    currentBalance: number;
    currentMortgageBalance: number | null;
    currencyCode: string;
}

function EquitySummaryCards({
    chartData,
    currentBalance,
    currentMortgageBalance,
    currencyCode,
}: EquitySummaryCardsProps) {
    const { accountMainLineColor, mortgageLineColor, equityLineColor } =
        useChartColors();

    const { marketHistory, mortgageHistory, equityHistory, equity } =
        useMemo(() => {
            const market = chartData.map((d) => ({
                date: d.month,
                value: d.value,
            }));
            const mortgage = chartData
                .filter(
                    (d) =>
                        d.mortgage_balance !== null &&
                        d.mortgage_balance !== undefined,
                )
                .map((d) => ({ date: d.month, value: d.mortgage_balance! }));
            const equityArr = chartData
                .filter(
                    (d) =>
                        d.mortgage_balance !== null &&
                        d.mortgage_balance !== undefined,
                )
                .map((d) => ({
                    date: d.month,
                    value: d.value - d.mortgage_balance!,
                }));

            const currentEquity =
                currentMortgageBalance !== null
                    ? currentBalance - currentMortgageBalance
                    : null;

            return {
                marketHistory: market,
                mortgageHistory: mortgage,
                equityHistory: equityArr,
                equity: currentEquity,
            };
        }, [chartData, currentBalance, currentMortgageBalance]);

    const marketTrend = useMemo(() => {
        if (marketHistory.length < 2) return null;
        const prev = marketHistory[0].value;
        const curr = marketHistory[marketHistory.length - 1].value;
        if (prev === 0) return null;
        return { diff: curr - prev, previous: prev, current: curr };
    }, [marketHistory]);

    const mortgageTrend = useMemo(() => {
        if (mortgageHistory.length < 2) return null;
        const prev = mortgageHistory[0].value;
        const curr = mortgageHistory[mortgageHistory.length - 1].value;
        if (prev === 0) return null;
        return { diff: curr - prev, previous: prev, current: curr };
    }, [mortgageHistory]);

    const equityTrend = useMemo(() => {
        if (equityHistory.length < 2) return null;
        const prev = equityHistory[0].value;
        const curr = equityHistory[equityHistory.length - 1].value;
        if (prev === 0) return null;
        return { diff: curr - prev, previous: prev, current: curr };
    }, [equityHistory]);

    return (
        <div className="grid gap-4 md:grid-cols-3">
            <SparklineCard
                title={__('Market Value')}
                amountInCents={currentBalance}
                currencyCode={currencyCode}
                history={marketHistory}
                lineColor={accountMainLineColor}
                trend={marketTrend}
            />
            <SparklineCard
                title={__('Mortgage Owed')}
                amountInCents={currentMortgageBalance ?? 0}
                currencyCode={currencyCode}
                history={mortgageHistory}
                lineColor={mortgageLineColor}
                trend={mortgageTrend}
            />
            <SparklineCard
                title={__('Equity')}
                amountInCents={equity ?? 0}
                currencyCode={currencyCode}
                history={equityHistory}
                lineColor={equityLineColor}
                trend={equityTrend}
            />
        </div>
    );
}

function SparklineCard({
    title,
    amountInCents,
    currencyCode,
    history,
    lineColor,
    trend,
}: {
    title: string;
    amountInCents: number;
    currencyCode: string;
    history: Array<{ date: string; value: number }>;
    lineColor: string;
    trend: { diff: number; previous: number; current: number } | null;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="flex items-center justify-between gap-6">
                    <div className="flex flex-col gap-1">
                        <div className="px-0 py-1">
                            <AmountDisplay
                                amountInCents={amountInCents}
                                currencyCode={currencyCode}
                                size="2xl"
                                weight="medium"
                                minimumFractionDigits={0}
                                maximumFractionDigits={0}
                            />
                        </div>
                        {trend && (
                            <AmountTrendIndicator
                                isPositive={trend.diff >= 0}
                                trend={Math.abs(trend.diff)}
                                label={__('vs start')}
                                className="text-sm"
                                previousAmount={trend.previous}
                                currentAmount={trend.current}
                                tooltipSide="bottom"
                                currencyCode={currencyCode}
                            />
                        )}
                    </div>
                    {history.length > 1 && (
                        <div className="h-[70px] w-full max-w-[250px] flex-1">
                            <ResponsiveContainer
                                width="100%"
                                height="100%"
                                initialDimension={{ width: 1, height: 1 }}
                            >
                                <LineChart data={history}>
                                    <Tooltip
                                        content={({ active, payload }) => {
                                            if (!active || !payload?.length)
                                                return null;
                                            const data = payload[0].payload as {
                                                date: string;
                                                value: number;
                                            };
                                            return (
                                                <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
                                                    <p className="mb-1 text-muted-foreground">
                                                        {data.date}
                                                    </p>
                                                    <p className="font-mono font-medium text-foreground tabular-nums">
                                                        <AmountDisplay
                                                            amountInCents={
                                                                data.value
                                                            }
                                                            currencyCode={
                                                                currencyCode
                                                            }
                                                            minimumFractionDigits={
                                                                0
                                                            }
                                                            maximumFractionDigits={
                                                                0
                                                            }
                                                        />
                                                    </p>
                                                </div>
                                            );
                                        }}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="value"
                                        stroke={lineColor}
                                        strokeWidth={2}
                                        dot={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function PropertyDetailsCard({
    detail,
    account,
    availableLoanAccounts,
    isEditing,
    onEditToggle,
}: {
    detail: RealEstateDetail;
    account: AccountWithDetails;
    availableLoanAccounts: Account[];
    isEditing: boolean;
    onEditToggle: (editing: boolean) => void;
}) {
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [formData, setFormData] = useState({
        property_type: detail.property_type,
        address: detail.address ?? '',
        purchase_price: detail.purchase_price ?? 0,
        purchase_date: detail.purchase_date ?? '',
        area_value: detail.area_value ?? '',
        area_unit: detail.area_unit ?? (null as AreaUnit | null),
        linked_loan_account_id:
            detail.linked_loan_account_id ?? (null as string | null),
        notes: detail.notes ?? '',
        revaluation_percentage:
            detail.revaluation_percentage != null
                ? String(detail.revaluation_percentage)
                : '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setIsSubmitting(true);

        router.patch(
            updateRealEstateDetail.url(account.id),
            {
                property_type: formData.property_type,
                address: formData.address || null,
                purchase_price: formData.purchase_price || null,
                purchase_date: formData.purchase_date || null,
                area_value: formData.area_value || null,
                area_unit: formData.area_unit,
                linked_loan_account_id: formData.linked_loan_account_id,
                notes: formData.notes || null,
                revaluation_percentage: formData.revaluation_percentage || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onEditToggle(false),
                onFinish: () => setIsSubmitting(false),
            },
        );
    }

    function handleCancel() {
        setFormData({
            property_type: detail.property_type,
            address: detail.address ?? '',
            purchase_price: detail.purchase_price ?? 0,
            purchase_date: detail.purchase_date ?? '',
            area_value: detail.area_value ?? '',
            area_unit: detail.area_unit ?? null,
            linked_loan_account_id: detail.linked_loan_account_id ?? null,
            notes: detail.notes ?? '',
            revaluation_percentage:
                detail.revaluation_percentage != null
                    ? String(detail.revaluation_percentage)
                    : '',
        });
        onEditToggle(false);
    }

    if (isEditing) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{__('Edit Property Details')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="edit_property_type">
                                    {__('Property Type')}
                                </Label>
                                <Select
                                    value={formData.property_type}
                                    onValueChange={(value) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            property_type:
                                                value as PropertyType,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {PROPERTY_TYPES.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {formatPropertyType(type)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="edit_purchase_date">
                                    {__('Purchase Date')}
                                </Label>
                                <Input
                                    id="edit_purchase_date"
                                    type="date"
                                    value={formData.purchase_date}
                                    onChange={(e) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            purchase_date: e.target.value,
                                        }))
                                    }
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="edit_address">
                                {__('Address')}
                            </Label>
                            <Input
                                id="edit_address"
                                value={formData.address}
                                onChange={(e) =>
                                    setFormData((prev) => ({
                                        ...prev,
                                        address: e.target.value,
                                    }))
                                }
                                placeholder={__('Property address')}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="edit_purchase_price">
                                {__('Purchase Price')}
                            </Label>
                            <AmountInput
                                id="edit_purchase_price"
                                value={formData.purchase_price}
                                onChange={(value) =>
                                    setFormData((prev) => ({
                                        ...prev,
                                        purchase_price: value,
                                    }))
                                }
                                currencyCode={account.currency_code}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="edit_area_value">
                                    {__('Area')}
                                </Label>
                                <Input
                                    id="edit_area_value"
                                    type="number"
                                    value={formData.area_value}
                                    onChange={(e) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            area_value: e.target.value,
                                        }))
                                    }
                                    placeholder="0"
                                    min="0"
                                    step="0.01"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="edit_area_unit">
                                    {__('Unit')}
                                </Label>
                                <Select
                                    value={formData.area_unit ?? 'none'}
                                    onValueChange={(value) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            area_unit:
                                                value === 'none'
                                                    ? null
                                                    : (value as AreaUnit),
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={__('Select unit')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            {__('None')}
                                        </SelectItem>
                                        {AREA_UNITS.map((unit) => (
                                            <SelectItem key={unit} value={unit}>
                                                {formatAreaUnit(unit)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        {availableLoanAccounts.length > 0 && (
                            <div className="space-y-2">
                                <Label htmlFor="edit_linked_loan">
                                    {__('Linked Mortgage / Loan')}
                                </Label>
                                <Select
                                    value={
                                        formData.linked_loan_account_id ??
                                        'none'
                                    }
                                    onValueChange={(value) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            linked_loan_account_id:
                                                value === 'none' ? null : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">
                                            {__('No linked loan')}
                                        </SelectItem>
                                        {availableLoanAccounts.map((loan) => (
                                            <SelectItem
                                                key={loan.id}
                                                value={loan.id}
                                            >
                                                {loan.name}{' '}
                                                {loan.bank
                                                    ? `(${loan.bank.name})`
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="edit_notes">{__('Notes')}</Label>
                            <Textarea
                                id="edit_notes"
                                value={formData.notes}
                                onChange={(e) =>
                                    setFormData((prev) => ({
                                        ...prev,
                                        notes: e.target.value,
                                    }))
                                }
                                placeholder={__(
                                    'Additional notes about this property',
                                )}
                                rows={3}
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="edit_revaluation_percentage">
                                {__('Annual Revaluation (%)')}
                            </Label>
                            <Input
                                id="edit_revaluation_percentage"
                                type="number"
                                value={formData.revaluation_percentage}
                                onChange={(e) =>
                                    setFormData((prev) => ({
                                        ...prev,
                                        revaluation_percentage: e.target.value,
                                    }))
                                }
                                placeholder="0.00"
                                min="-100"
                                max="100"
                                step="0.01"
                            />
                            <p className="text-xs text-muted-foreground">
                                {__(
                                    'Annual percentage applied monthly. Use negative values for depreciation.',
                                )}
                            </p>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={handleCancel}
                                disabled={isSubmitting}
                            >
                                {__('Cancel')}
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? __('Saving...') : __('Save')}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        );
    }

    const linkedLoan = detail.linked_loan_account;

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>{__('Property Details')}</CardTitle>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => onEditToggle(true)}
                >
                    <Pencil className="mr-1 h-3.5 w-3.5" />
                    {__('Edit')}
                </Button>
            </CardHeader>
            <CardContent>
                <dl className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            {__('Property Type')}
                        </dt>
                        <dd className="font-medium">
                            {formatPropertyType(detail.property_type)}
                        </dd>
                    </div>

                    {detail.address && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {__('Address')}
                            </dt>
                            <dd className="font-medium">{detail.address}</dd>
                        </div>
                    )}

                    {detail.purchase_price !== null && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {__('Purchase Price')}
                            </dt>
                            <dd className="font-medium">
                                <AmountDisplay
                                    amountInCents={detail.purchase_price}
                                    currencyCode={account.currency_code}
                                />
                            </dd>
                        </div>
                    )}

                    {detail.purchase_date && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {__('Purchase Date')}
                            </dt>
                            <dd className="font-medium">
                                {formatDateMedium(detail.purchase_date)}
                            </dd>
                        </div>
                    )}

                    {detail.area_value && detail.area_unit && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {__('Area')}
                            </dt>
                            <dd className="font-medium">
                                {detail.area_value}{' '}
                                {formatAreaUnit(detail.area_unit)}
                            </dd>
                        </div>
                    )}

                    {linkedLoan && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {__('Linked Mortgage / Loan')}
                            </dt>
                            <dd className="font-medium">
                                <a
                                    href={show.url(linkedLoan.id)}
                                    className="text-primary underline-offset-4 hover:underline"
                                >
                                    {linkedLoan.name}
                                    {linkedLoan.bank
                                        ? ` (${linkedLoan.bank.name})`
                                        : ''}
                                </a>
                            </dd>
                        </div>
                    )}

                    {detail.revaluation_percentage != null && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {__('Annual Revaluation')}
                            </dt>
                            <dd className="font-medium">
                                {Number(detail.revaluation_percentage) > 0
                                    ? '+'
                                    : ''}
                                {detail.revaluation_percentage}%{__('/year')}
                            </dd>
                        </div>
                    )}
                </dl>

                {detail.notes && (
                    <div className="mt-4 border-t pt-4">
                        <dt className="text-sm text-muted-foreground">
                            {__('Notes')}
                        </dt>
                        <dd className="mt-1 text-sm whitespace-pre-wrap">
                            {detail.notes}
                        </dd>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function LoanDetailsCard({
    detail,
    account,
    loanAccountId,
    isEditing,
    onEditToggle,
    onEditDialogOpen,
}: {
    detail: LoanDetail | null;
    account: AccountWithDetails;
    loanAccountId?: string;
    isEditing: boolean;
    onEditToggle: (editing: boolean) => void;
    onEditDialogOpen?: () => void;
}) {
    const targetAccountId = loanAccountId ?? account.id;
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [formData, setFormData] = useState({
        annual_interest_rate: detail?.annual_interest_rate ?? '',
        loan_term_months: detail?.loan_term_months
            ? String(detail.loan_term_months)
            : '',
        start_date: detail?.start_date?.slice(0, 10) ?? '',
        original_amount: detail?.original_amount ?? 0,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        setIsSubmitting(true);
        setErrors({});

        router.patch(
            updateLoanDetail.url(targetAccountId),
            {
                annual_interest_rate: formData.annual_interest_rate,
                loan_term_months: Number(formData.loan_term_months),
                start_date: formData.start_date,
                original_amount: formData.original_amount,
            },
            {
                preserveScroll: true,
                onSuccess: () => onEditToggle(false),
                onError: (errors) => setErrors(errors),
                onFinish: () => setIsSubmitting(false),
            },
        );
    }

    function handleCancel() {
        setFormData({
            annual_interest_rate: detail?.annual_interest_rate ?? '',
            loan_term_months: detail?.loan_term_months
                ? String(detail.loan_term_months)
                : '',
            start_date: detail?.start_date?.slice(0, 10) ?? '',
            original_amount: detail?.original_amount ?? 0,
        });
        setErrors({});
        onEditToggle(false);
    }

    if (isEditing) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>{__('Edit Loan Details')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="edit_annual_interest_rate">
                                    {__('Annual Interest Rate (%)')}
                                </Label>
                                <Input
                                    id="edit_annual_interest_rate"
                                    type="number"
                                    value={formData.annual_interest_rate}
                                    onChange={(e) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            annual_interest_rate:
                                                e.target.value,
                                        }))
                                    }
                                    placeholder="3.500"
                                    min="0"
                                    max="99.999"
                                    step="0.001"
                                />
                                <InputError
                                    message={errors.annual_interest_rate}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="edit_loan_term_months">
                                    {__('Loan Term (months)')}
                                </Label>
                                <Input
                                    id="edit_loan_term_months"
                                    type="number"
                                    value={formData.loan_term_months}
                                    onChange={(e) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            loan_term_months: e.target.value,
                                        }))
                                    }
                                    placeholder="360"
                                    min="1"
                                    max="600"
                                />
                                <InputError message={errors.loan_term_months} />
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="edit_loan_start_date">
                                    {__('Start Date')}
                                </Label>
                                <Input
                                    id="edit_loan_start_date"
                                    type="date"
                                    value={formData.start_date}
                                    onChange={(e) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            start_date: e.target.value,
                                        }))
                                    }
                                />
                                <InputError message={errors.start_date} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="edit_original_amount">
                                    {__('Original Amount')}
                                </Label>
                                <AmountInput
                                    id="edit_original_amount"
                                    value={formData.original_amount}
                                    onChange={(value) =>
                                        setFormData((prev) => ({
                                            ...prev,
                                            original_amount: value,
                                        }))
                                    }
                                    currencyCode={account.currency_code}
                                />
                                <InputError message={errors.original_amount} />
                            </div>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={handleCancel}
                                disabled={isSubmitting}
                            >
                                {__('Cancel')}
                            </Button>
                            <Button type="submit" disabled={isSubmitting}>
                                {isSubmitting ? __('Saving...') : __('Save')}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        );
    }

    if (!detail) {
        return (
            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <CardTitle>{__('Loan Details')}</CardTitle>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onEditDialogOpen
                                ? onEditDialogOpen()
                                : onEditToggle(true)
                        }
                    >
                        <Pencil className="mr-1 h-3.5 w-3.5" />
                        {__('Add')}
                    </Button>
                </CardHeader>
                <CardContent>
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'No loan details yet. Add interest rate, term, and amount to track amortization.',
                        )}
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>{__('Loan Details')}</CardTitle>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                        onEditDialogOpen
                            ? onEditDialogOpen()
                            : onEditToggle(true)
                    }
                >
                    <Pencil className="mr-1 h-3.5 w-3.5" />
                    {__('Edit')}
                </Button>
            </CardHeader>
            <CardContent>
                <dl className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt className="text-sm text-muted-foreground">
                            {__('Annual Interest Rate')}
                        </dt>
                        <dd className="font-medium">
                            {detail.annual_interest_rate}%
                        </dd>
                    </div>

                    <div>
                        <dt className="text-sm text-muted-foreground">
                            {__('Loan Term')}
                        </dt>
                        <dd className="font-medium">
                            {detail.loan_term_months} {__('months')}
                            {detail.loan_term_months >= 12 && (
                                <span className="ml-1 text-sm text-muted-foreground">
                                    ({Math.floor(detail.loan_term_months / 12)}{' '}
                                    {__('years')})
                                </span>
                            )}
                        </dd>
                    </div>

                    <div>
                        <dt className="text-sm text-muted-foreground">
                            {__('Start Date')}
                        </dt>
                        <dd className="font-medium">
                            {formatDateMedium(detail.start_date)}
                        </dd>
                    </div>

                    <div>
                        <dt className="text-sm text-muted-foreground">
                            {__('Original Amount')}
                        </dt>
                        <dd className="font-medium">
                            <AmountDisplay
                                amountInCents={detail.original_amount}
                                currencyCode={account.currency_code}
                            />
                        </dd>
                    </div>

                    {detail.monthly_payment !== null && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {__('Monthly Payment')}
                            </dt>
                            <dd className="font-medium">
                                <AmountDisplay
                                    amountInCents={detail.monthly_payment}
                                    currencyCode={account.currency_code}
                                />
                            </dd>
                        </div>
                    )}

                    {detail.remaining_months !== null && (
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {__('Remaining')}
                            </dt>
                            <dd className="font-medium">
                                {detail.remaining_months} {__('months')}
                                {detail.remaining_months >= 12 && (
                                    <span className="ml-1 text-sm text-muted-foreground">
                                        (
                                        {Math.floor(
                                            detail.remaining_months / 12,
                                        )}{' '}
                                        {__('years')})
                                    </span>
                                )}
                            </dd>
                        </div>
                    )}
                </dl>
            </CardContent>
        </Card>
    );
}
