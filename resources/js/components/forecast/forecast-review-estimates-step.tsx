import { store as storeEstimate } from '@/actions/App/Http/Controllers/Forecast/ForecastEstimateController';
import {
    useBandHint,
    useEstimateBasis,
} from '@/components/forecast/forecast-plan-cells';
import {
    ReviewSectionTitle,
    ReviewStepLayout,
} from '@/components/forecast/forecast-review-layout';
import { AmountDisplay } from '@/components/ui/amount-display';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState, EmptyStateDescription } from '@/components/ui/empty-state';
import {
    StatTile,
    StatTileLabel,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { useLocale } from '@/hooks/use-locale';
import { monthLongName } from '@/lib/forecast/format';
import { estimateName, reviewEstimateId } from '@/lib/forecast/review';
import { cn } from '@/lib/utils';
import {
    type ForecastEstimate,
    type ForecastReviewEstimate,
    type ForecastReviewEstimates,
    type ForecastReviewTransfer,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';

interface EstimatesStepProps {
    estimates: ForecastReviewEstimates | null;
    /** Several accounts are reviewed, so each row names its own. */
    showAccounts: boolean;
    onSkip: () => void;
    onNext: () => void;
}

/**
 * Step 1 of the review: how next month is estimated, income categories
 * first, then expenses ("Other income" / "Other spending" last), the usual
 * transfers between the user's accounts apart, and the month's totals.
 * Each figure can be changed in place: it is stored right away as the
 * user's own figure from next month on, the same adjustment the plan's
 * "from here on" makes.
 */
export function ForecastReviewEstimatesStep({
    estimates,
    showAccounts,
    onSkip,
    onNext,
}: EstimatesStepProps) {
    const locale = useLocale();
    const isEmpty =
        !estimates ||
        (estimates.income.length === 0 &&
            estimates.expenses.length === 0 &&
            estimates.transfers.length === 0);

    return (
        <ReviewStepLayout
            eyebrow={__('Step 1 of 2 · Forecast')}
            title={__('This is how we have estimated your month')}
            description={
                estimates
                    ? __(
                          'What each category usually moves in :month, from the median of your last 3 months or your budget. Change any figure and it is saved at once, from :month on.',
                          { month: monthLongName(estimates.month, locale) },
                      )
                    : __(
                          'What each category usually moves in a month, from the median of your last 3 months or your budget.',
                      )
            }
            footer={
                <div className="ml-auto flex gap-2">
                    <Button variant="outline" onClick={onSkip}>
                        {__('Not now')}
                    </Button>
                    <Button onClick={onNext} data-test="forecast-review-next">
                        {__('Next: yearly payments')}
                    </Button>
                </div>
            }
        >
            {isEmpty || !estimates ? (
                <EmptyState size="inline">
                    <EmptyStateDescription>
                        {__(
                            'There is not enough history yet to estimate your month. Add your yearly payments in the next step, and the estimate will appear as your movements come in.',
                        )}
                    </EmptyStateDescription>
                </EmptyState>
            ) : (
                <>
                    <EstimateSection
                        side="income"
                        rows={estimates.income}
                        month={estimates.month}
                        showAccounts={showAccounts}
                    />
                    <EstimateSection
                        side="expenses"
                        rows={estimates.expenses}
                        month={estimates.month}
                        showAccounts={showAccounts}
                    />
                    <TransferSection transfers={estimates.transfers} />
                    <Totals estimates={estimates} />
                </>
            )}
        </ReviewStepLayout>
    );
}

function EstimateSection({
    side,
    rows,
    month,
    showAccounts,
}: {
    side: 'income' | 'expenses';
    rows: ForecastReviewEstimate[];
    month: string;
    showAccounts: boolean;
}) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <section
            className="flex flex-col gap-1"
            data-test={`forecast-review-section-${side}`}
        >
            <ReviewSectionTitle>
                {side === 'income' ? __('Income') : __('Expenses')}
            </ReviewSectionTitle>
            {rows.map((row) => (
                <EstimateRow
                    key={reviewEstimateId(row)}
                    row={row}
                    month={month}
                    showAccount={showAccounts}
                />
            ))}
        </section>
    );
}

/** The row in the shape the plan's helpers read, to say its basis the same way. */
function asPlanEstimate(
    row: ForecastReviewEstimate,
    month: string,
): ForecastEstimate {
    return {
        kind: row.kind,
        basis: row.basis,
        budgetAmount: row.budgetAmount,
        amounts: { [month]: row.kind === 'income' ? row.amount : -row.amount },
        current: null,
    };
}

function EstimateRow({
    row,
    month,
    showAccount,
}: {
    row: ForecastReviewEstimate;
    month: string;
    showAccount: boolean;
}) {
    const basis = useEstimateBasis(row.currencyCode);
    const bandHint = useBandHint(row.currencyCode);
    const [editing, setEditing] = useState(false);
    const name = estimateName(row);
    const detail = [
        basis(asPlanEstimate(row, month)),
        showAccount ? row.accountName : null,
        bandHint(row.kind, row.amount),
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <Card size="flush" data-test="forecast-review-estimate">
            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-3 py-2.5">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="truncate text-sm font-medium">{name}</span>
                    <span className="text-xs text-muted-foreground">
                        {detail}
                    </span>
                </div>
                {editing ? (
                    <EstimateEditor
                        row={row}
                        month={month}
                        name={name}
                        onDone={() => setEditing(false)}
                    />
                ) : (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="-mr-2 tabular-nums"
                        onClick={() => setEditing(true)}
                        aria-label={__('Change :name', { name })}
                        data-test="forecast-review-estimate-edit"
                    >
                        <AmountDisplay
                            amountInCents={
                                row.kind === 'income' ? row.amount : -row.amount
                            }
                            currencyCode={row.currencyCode}
                            showSign
                            maximumFractionDigits={0}
                            minimumFractionDigits={0}
                            className={cn(
                                'text-sm font-medium',
                                row.kind === 'income' && 'text-success',
                            )}
                        />
                    </Button>
                )}
            </div>
        </Card>
    );
}

/**
 * The figure being changed: saved as the user's own from next month on,
 * after which the review reloads with it.
 */
function EstimateEditor({
    row,
    month,
    name,
    onDone,
}: {
    row: ForecastReviewEstimate;
    month: string;
    name: string;
    onDone: () => void;
}) {
    const [amount, setAmount] = useState(row.amount);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inputId = `forecast-review-amount-${reviewEstimateId(row)}`;

    const save = () => {
        if (amount === row.amount) {
            onDone();

            return;
        }

        setProcessing(true);
        setError(null);
        router.post(
            storeEstimate.url(),
            {
                account_id: row.accountId,
                category_id: row.categoryId,
                kind: row.kind,
                month,
                amount,
                scope: 'from_here',
            },
            {
                only: ['review', 'forecast', 'projectionInput'],
                preserveScroll: true,
                preserveState: true,
                onSuccess: onDone,
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            __('We could not save it. Try again.'),
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="flex w-full flex-col items-end gap-1 sm:w-auto">
            <div className="flex items-center gap-1">
                <label htmlFor={inputId} className="sr-only">
                    {__('New figure for :name', { name })}
                </label>
                <AmountInput
                    id={inputId}
                    value={amount}
                    onChange={(value) => setAmount(Math.abs(value))}
                    currencyCode={row.currencyCode}
                    commitOnChange
                    className="h-8 w-36"
                />
                <Button
                    size="icon"
                    className="size-8"
                    onClick={save}
                    disabled={processing}
                    aria-label={__('Save')}
                    data-test="forecast-review-estimate-save"
                >
                    <Check />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    onClick={onDone}
                    disabled={processing}
                    aria-label={__('Cancel')}
                >
                    <X />
                </Button>
            </div>
            {error && (
                <span className="text-xs text-destructive" role="alert">
                    {error}
                </span>
            )}
        </div>
    );
}

function TransferSection({
    transfers,
}: {
    transfers: ForecastReviewTransfer[];
}) {
    if (transfers.length === 0) {
        return null;
    }

    return (
        <section
            className="flex flex-col gap-1"
            data-test="forecast-review-section-transfers"
        >
            <ReviewSectionTitle>
                {__('Transfers between your accounts')}
            </ReviewSectionTitle>
            <Card size="flush">
                <ul className="flex flex-col divide-y">
                    {transfers.map((transfer) => (
                        <li
                            key={transfer.key}
                            className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <span className="min-w-0 truncate">
                                {`${transfer.fromAccountName} → ${transfer.toAccountName}`}
                            </span>
                            <AmountDisplay
                                amountInCents={transfer.amount}
                                currencyCode={transfer.currencyCode}
                                maximumFractionDigits={0}
                                minimumFractionDigits={0}
                                className="text-sm text-muted-foreground tabular-nums"
                            />
                        </li>
                    ))}
                </ul>
            </Card>
        </section>
    );
}

function Totals({ estimates }: { estimates: ForecastReviewEstimates }) {
    const { totals, currencyCode } = estimates;
    const tiles = [
        { label: __('Income'), amount: totals.income, tone: 'text-success' },
        { label: __('Expenses'), amount: -totals.expenses, tone: '' },
        {
            label: __('Net per month'),
            amount: totals.net,
            tone: totals.net > 0 ? 'text-success' : '',
        },
    ];

    return (
        <section
            className="mt-4 flex flex-col gap-2"
            data-test="forecast-review-totals"
        >
            <Card className="grid grid-cols-3 gap-3 p-3">
                {tiles.map((tile) => (
                    <StatTile key={tile.label} size="sm">
                        <StatTileLabel>{tile.label}</StatTileLabel>
                        <StatTileValue className={tile.tone}>
                            <AmountDisplay
                                amountInCents={tile.amount}
                                currencyCode={currencyCode}
                                showSign
                                maximumFractionDigits={0}
                                minimumFractionDigits={0}
                            />
                        </StatTileValue>
                    </StatTile>
                ))}
            </Card>
            {estimates.unusableBudgets.length > 0 && (
                <span className="text-xs text-muted-foreground">
                    {__(
                        'We cannot use :budgets: they are not monthly, follow labels or mix categories, so their categories use the median.',
                        { budgets: estimates.unusableBudgets.join(', ') },
                    )}
                </span>
            )}
            <span className="text-xs text-muted-foreground">
                {estimates.unconvertedCurrencies.length > 0
                    ? __(
                          'Transfers between your accounts are not counted. Accounts in :currencies are left out: there is no exchange rate for today.',
                          {
                              currencies:
                                  estimates.unconvertedCurrencies.join(', '),
                          },
                      )
                    : __(
                          'Transfers between your accounts are not counted: they only move money between them.',
                      )}
            </span>
        </section>
    );
}
