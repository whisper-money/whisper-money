import {
    CollapsibleSection,
    FOOTNOTE,
    HEAD_CELL,
} from '@/components/transactions/import-section';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/hooks/use-locale';
import {
    buildMappingReport,
    getLatestTransactionDate,
} from '@/lib/file-parser';
import {
    DateFormat,
    type ColumnMapping,
    type ColumnOption,
    type MappedField,
    type MappingReport,
    type ParsedRow,
    type RowProblem,
} from '@/types/import';
import {
    currencyDecimals,
    formatCurrency,
    toMajorUnits,
} from '@/utils/currency';
import { formatDateMedium, formatRelativeDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { AlertCircle, Check, XCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const NONE = '__none__';

/** The table's own shape, shared by every row so the columns line up. */
// One column on a phone, where a three-column table cannot breathe; the table
// proper from md up.
const ROW_GRID =
    'grid grid-cols-1 gap-2 md:grid-cols-[7.5rem_minmax(0,1fr)_17rem] md:items-start md:gap-4';

const DATE_FORMAT_LABELS: Record<DateFormat, string> = {
    [DateFormat.YearMonthDay]: 'YYYY-MM-DD',
    [DateFormat.MonthDayYear]: 'MM-DD-YYYY',
    [DateFormat.DayMonthYear]: 'DD-MM-YYYY',
    [DateFormat.YearMonthDayCompact]: 'YYYYMMDD',
};

interface ImportStepMappingProps {
    columnOptions: ColumnOption[];
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    parsedData: ParsedRow[];
    rowNumbers: number[];
    currencyCode: string;
    supportedCurrencies: string[];
    calculateBalances: boolean;
    referenceBalance: number | null;
    referenceBalancePrefilled: boolean;
    calculateBalancesAvailable: boolean;
    onMappingChange: (
        field: keyof ColumnMapping,
        value: string | string[],
    ) => void;
    onDateFormatChange: (format: DateFormat) => void;
    onCalculateBalancesChange: (enabled: boolean) => void;
    onReferenceBalanceChange: (balanceInCents: number) => void;
    onLatestDateChange: (date: string | null) => void;
    onNext: () => void;
    onBack: () => void;
}

/** One "map this field to a column" select, shared by every row of the table. */
function ColumnSelect({
    id,
    value,
    placeholder,
    optional,
    columnOptions,
    onChange,
}: {
    id: string;
    value: string | null;
    placeholder: string;
    optional?: boolean;
    columnOptions: ColumnOption[];
    onChange: (value: string) => void;
}) {
    return (
        <Select
            value={value || (optional ? NONE : '')}
            onValueChange={(next) => onChange(next === NONE ? '' : next)}
        >
            <SelectTrigger id={id}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {optional && <SelectItem value={NONE}>{__('None')}</SelectItem>}
                {columnOptions.map((option, index) => (
                    <SelectItem
                        key={`${id}-${option.value}-${index}`}
                        value={option.value}
                    >
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** How many rows a field could be read from, and what it made of them. */
function FieldStatus({
    ok,
    skipped,
    adjusted,
    total,
    skippedLabel,
    adjustedLabel,
    sample,
}: {
    ok: number;
    skipped: number;
    adjusted: number;
    total: number;
    skippedLabel?: string;
    adjustedLabel?: string;
    sample?: string | null;
}) {
    const clean = skipped === 0 && adjusted === 0;

    return (
        <div className="flex flex-col gap-1 md:pt-1.5">
            <div className="flex items-center gap-1.5">
                {clean ? (
                    <Check className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                ) : skipped > 0 ? (
                    <XCircle className="size-3.5 shrink-0 text-destructive" />
                ) : (
                    <AlertCircle className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400" />
                )}
                <span className="text-[13px]">
                    {clean
                        ? `${__('All')} ${total}`
                        : `${ok} ${__('of')} ${total}`}
                    {skipped > 0 && skippedLabel
                        ? ` · ${skipped} ${skippedLabel}`
                        : ''}
                    {skipped === 0 && adjusted > 0 && adjustedLabel
                        ? ` · ${adjusted} ${adjustedLabel}`
                        : ''}
                </span>
            </div>
            {sample && (
                <span className="text-xs text-muted-foreground">{sample}</span>
            )}
        </div>
    );
}

/** The rows that will not import as written, and why. */
function ProblemRows({
    problems,
    hasCurrency,
}: {
    problems: RowProblem[];
    hasCurrency: boolean;
}) {
    const cellClass = (problem: RowProblem, field: MappedField) => {
        const fault = problem.faults.find((entry) => entry.field === field);

        if (!fault) {
            return '';
        }

        return fault.severity === 'skipped'
            ? 'rounded bg-destructive/10 px-1 text-destructive'
            : 'rounded bg-amber-500/10 px-1 text-amber-600 dark:text-amber-400';
    };

    const columns: { field: MappedField; label: string; mono: boolean }[] = [
        { field: 'transaction_date', label: __('Date'), mono: true },
        { field: 'description', label: __('Description'), mono: false },
        { field: 'amount', label: __('Amount'), mono: true },
        ...(hasCurrency
            ? [
                  {
                      field: 'currency' as MappedField,
                      label: __('Currency'),
                      mono: true,
                  },
              ]
            : []),
    ];

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-[13px]">
                <thead>
                    <tr className="bg-muted">
                        <th className={`px-4 py-2 text-left ${HEAD_CELL}`}>
                            {__('Row')}
                        </th>
                        {columns.map((column) => (
                            <th
                                key={column.field}
                                className={`px-4 py-2 text-left ${HEAD_CELL}`}
                            >
                                {column.label}
                            </th>
                        ))}
                        <th className={`px-4 py-2 text-left ${HEAD_CELL}`}>
                            {__('Why')}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {problems.map((problem) => (
                        <tr key={problem.rowNumber} className="border-t">
                            <td className="px-4 py-2 font-mono text-muted-foreground">
                                {problem.rowNumber}
                            </td>
                            {columns.map((column) => (
                                <td
                                    key={column.field}
                                    className={`max-w-[220px] truncate px-4 py-2 ${column.mono ? 'font-mono whitespace-nowrap' : ''}`}
                                >
                                    <span
                                        className={cellClass(
                                            problem,
                                            column.field,
                                        )}
                                    >
                                        {problem.cells[column.field] ||
                                            __('empty')}
                                    </span>
                                </td>
                            ))}
                            <td
                                className={`px-4 py-2 ${
                                    problem.severity === 'skipped'
                                        ? 'text-destructive'
                                        : 'text-amber-600 dark:text-amber-400'
                                }`}
                            >
                                {problem.faults
                                    .map((fault) => fault.reason)
                                    .join(' · ')}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <p className={FOOTNOTE}>
                {__(
                    'Fix them in your file and upload it again, or carry on without those rows.',
                )}
            </p>
        </div>
    );
}

export function ImportStepMapping({
    columnOptions,
    columnMapping,
    dateFormat,
    dateFormatDetected,
    parsedData,
    rowNumbers,
    currencyCode,
    supportedCurrencies,
    calculateBalances,
    referenceBalance,
    referenceBalancePrefilled,
    calculateBalancesAvailable,
    onMappingChange,
    onDateFormatChange,
    onCalculateBalancesChange,
    onReferenceBalanceChange,
    onLatestDateChange,
    onNext,
    onBack,
}: ImportStepMappingProps) {
    const locale = useLocale();
    const [optionalOpen, setOptionalOpen] = useState(false);
    const [problemsOpen, setProblemsOpen] = useState(false);

    const descriptionColumns = useMemo(
        () =>
            Array.isArray(columnMapping.description)
                ? columnMapping.description
                : columnMapping.description
                  ? [columnMapping.description]
                  : [],
        [columnMapping.description],
    );

    const balanceColumnSet = !!columnMapping.balance;
    const effectiveCalculate = calculateBalances && !balanceColumnSet;

    const latestDate = useMemo(() => {
        if (!effectiveCalculate) {
            return null;
        }
        return getLatestTransactionDate(parsedData, columnMapping, dateFormat);
    }, [effectiveCalculate, parsedData, columnMapping, dateFormat]);

    useEffect(() => {
        onLatestDateChange(latestDate);
    }, [latestDate, onLatestDateChange]);

    const baseMappingValid =
        !!columnMapping.transaction_date &&
        !!columnMapping.description &&
        !!columnMapping.amount;

    const report: MappingReport | null = useMemo(() => {
        if (!baseMappingValid) {
            return null;
        }

        return buildMappingReport(
            parsedData,
            rowNumbers,
            columnMapping,
            dateFormat,
            currencyCode,
            supportedCurrencies,
        );
    }, [
        baseMappingValid,
        parsedData,
        rowNumbers,
        columnMapping,
        dateFormat,
        currencyCode,
        supportedCurrencies,
    ]);

    const isValid =
        baseMappingValid &&
        (report === null || report.readyCount > 0) &&
        (!effectiveCalculate ||
            (latestDate !== null &&
                referenceBalance !== null &&
                referenceBalance !== undefined));

    const mappedOptional = [
        columnMapping.currency && __('Currency'),
        columnMapping.balance && __('Balance'),
        columnMapping.creditor_name && __('Creditor'),
        columnMapping.debtor_name && __('Debtor'),
    ].filter(Boolean) as string[];

    const handleDescriptionChange = (columns: string[]) => {
        // Keep them in file order: the chips then read the same way round as
        // the description they produce, which is joined in this order.
        const next = [...columns].sort(
            (a, b) =>
                columnOptions.findIndex((option) => option.value === a) -
                columnOptions.findIndex((option) => option.value === b),
        );

        if (next.length === 0) {
            onMappingChange('description', '');
        } else if (next.length === 1) {
            onMappingChange('description', next[0]);
        } else {
            onMappingChange('description', next);
        }
    };

    const dateRangeSample = report?.dateRange
        ? report.dateRange.from === report.dateRange.to
            ? formatDateMedium(report.dateRange.from, locale)
            : `${formatDateMedium(report.dateRange.from, locale)} → ${formatDateMedium(report.dateRange.to, locale)}`
        : null;

    // With a currency column mapped the rows are not all in one currency, so the
    // range is shown as bare numbers rather than claiming the account's.
    const formatRangeAmount = (cents: number) =>
        columnMapping.currency
            ? new Intl.NumberFormat(locale, {
                  minimumFractionDigits: currencyDecimals(currencyCode),
                  maximumFractionDigits: currencyDecimals(currencyCode),
              }).format(toMajorUnits(cents, currencyCode))
            : formatCurrency(cents, currencyCode, locale);

    const amountRangeSample = report?.amountRange
        ? `${formatRangeAmount(report.amountRange.min)} → ${formatRangeAmount(report.amountRange.max)}`
        : null;

    return (
        <div className="flex flex-col gap-4">
            {/* What the import needs */}
            <div className="overflow-hidden rounded-lg border">
                <div
                    className={`${ROW_GRID} hidden bg-muted px-4 py-2.5 md:grid`}
                >
                    <span className={HEAD_CELL}>{__('Field')}</span>
                    <span className={HEAD_CELL}>
                        {__('Column in your file')}
                    </span>
                    <span className={HEAD_CELL}>
                        {report
                            ? `${__('Across all')} ${report.total} ${__('rows')}`
                            : ''}
                    </span>
                </div>

                <div className={`${ROW_GRID} border-t px-4 py-3`}>
                    <Label htmlFor="date-column" className="md:pt-2.5">
                        {__('Date')} <span className="text-destructive">*</span>
                    </Label>
                    <div className="flex flex-col gap-2">
                        <ColumnSelect
                            id="date-column"
                            value={columnMapping.transaction_date}
                            placeholder={__('Select date column')}
                            columnOptions={columnOptions}
                            onChange={(value) =>
                                onMappingChange('transaction_date', value)
                            }
                        />
                        <div className="flex items-center gap-2">
                            <span
                                className={`text-xs ${dateFormatDetected ? 'text-muted-foreground' : 'text-amber-600 dark:text-amber-400'}`}
                            >
                                {dateFormatDetected
                                    ? __('Reading as')
                                    : __('Check this — reading as')}
                            </span>
                            <Select
                                value={dateFormat}
                                onValueChange={(value) =>
                                    onDateFormatChange(value as DateFormat)
                                }
                            >
                                <SelectTrigger
                                    id="date-format"
                                    className="h-7 w-auto gap-1.5 px-2 text-xs"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(DATE_FORMAT_LABELS).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    {report && (
                        <FieldStatus
                            {...report.fields.transaction_date}
                            total={report.total}
                            skippedLabel={__('unreadable')}
                            sample={dateRangeSample}
                        />
                    )}
                </div>

                <div className={`${ROW_GRID} border-t px-4 py-3`}>
                    <Label htmlFor="description-column" className="md:pt-2.5">
                        {__('Description')}{' '}
                        <span className="text-destructive">*</span>
                    </Label>
                    <div className="flex flex-col gap-1.5">
                        <MultiSelect
                            id="description-column"
                            options={columnOptions.map((option) => ({
                                value: option.value,
                                label: option.label,
                            }))}
                            selected={descriptionColumns}
                            onChange={handleDescriptionChange}
                            placeholder={__('Select description column')}
                        />
                        <span className="text-xs text-muted-foreground">
                            {__(
                                'Several columns join into one description, one per line.',
                            )}
                        </span>
                    </div>
                    {report && (
                        <FieldStatus
                            {...report.fields.description}
                            total={report.total}
                            skippedLabel={__('have none')}
                            sample={report.descriptionSample}
                        />
                    )}
                </div>

                <div className={`${ROW_GRID} border-t px-4 py-3`}>
                    <Label htmlFor="amount-column" className="md:pt-2.5">
                        {__('Amount')}{' '}
                        <span className="text-destructive">*</span>
                    </Label>
                    <ColumnSelect
                        id="amount-column"
                        value={columnMapping.amount}
                        placeholder={__('Select amount column')}
                        columnOptions={columnOptions}
                        onChange={(value) => onMappingChange('amount', value)}
                    />
                    {report && (
                        <FieldStatus
                            {...report.fields.amount}
                            total={report.total}
                            skippedLabel={__('unreadable')}
                            sample={amountRangeSample}
                        />
                    )}
                </div>
            </div>

            {/* The rows that will not arrive as written */}
            {report && report.problems.length > 0 && (
                <CollapsibleSection
                    open={problemsOpen}
                    onOpenChange={setProblemsOpen}
                    className={
                        report.skippedCount > 0
                            ? 'border-destructive/40'
                            : 'border-amber-500/40'
                    }
                    triggerClassName={
                        report.skippedCount > 0
                            ? 'bg-destructive/5'
                            : 'bg-amber-500/5'
                    }
                    hint={__('Show them')}
                    title={
                        <>
                            {report.skippedCount > 0 ? (
                                <XCircle className="size-4 shrink-0 text-destructive" />
                            ) : (
                                <AlertCircle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                            )}
                            <span className="text-sm font-medium">
                                {report.skippedCount > 0
                                    ? `${report.skippedCount} ${__("rows won't be imported")}`
                                    : `${report.adjustedCount} ${__('rows will change')}`}
                            </span>
                            {report.skippedCount > 0 &&
                                report.adjustedCount > 0 && (
                                    <span className="text-xs font-normal text-muted-foreground">
                                        · {report.adjustedCount}{' '}
                                        {__('more will change')}
                                    </span>
                                )}
                        </>
                    }
                >
                    <ProblemRows
                        problems={report.problems}
                        hasCurrency={!!columnMapping.currency}
                    />
                </CollapsibleSection>
            )}

            {/* Folded away until asked for */}
            <CollapsibleSection
                open={optionalOpen}
                onOpenChange={setOptionalOpen}
                className="border-sidebar-border bg-sidebar"
                contentClassName="border-sidebar-border bg-background"
                hint={__('Currency, balance, creditor, debtor')}
                title={
                    <>
                        <span className="text-sm font-medium">
                            {__('Optional fields')}
                        </span>
                        {!optionalOpen && mappedOptional.length > 0 && (
                            <span className="rounded-md border bg-muted px-1.5 text-xs font-medium">
                                {mappedOptional.join(', ')}
                            </span>
                        )}
                    </>
                }
            >
                <div className={`${ROW_GRID} px-4 py-3`}>
                    <Label htmlFor="currency-column" className="md:pt-2.5">
                        {__('Currency')}
                    </Label>
                    <ColumnSelect
                        id="currency-column"
                        value={columnMapping.currency}
                        placeholder={__('Select currency column')}
                        optional
                        columnOptions={columnOptions}
                        onChange={(value) => onMappingChange('currency', value)}
                    />
                    {columnMapping.currency && report ? (
                        <FieldStatus
                            {...report.fields.currency}
                            total={report.total}
                            adjustedLabel={`${__('fall back to')} ${currencyCode}`}
                            sample={
                                report.currencies.used.length > 0
                                    ? report.currencies.used.join(', ')
                                    : null
                            }
                        />
                    ) : (
                        <p className="text-xs text-muted-foreground md:pt-2.5">
                            {__('Every row uses')} {currencyCode}
                        </p>
                    )}
                </div>

                <div className={`${ROW_GRID} border-t px-4 py-3`}>
                    <Label htmlFor="balance-column" className="md:pt-2.5">
                        {__('Balance')}
                    </Label>
                    <div className="flex flex-col gap-3">
                        <ColumnSelect
                            id="balance-column"
                            value={columnMapping.balance}
                            placeholder={__('Select balance column')}
                            optional
                            columnOptions={columnOptions}
                            onChange={(value) =>
                                onMappingChange('balance', value)
                            }
                        />

                        {calculateBalancesAvailable && (
                            <div className="flex flex-col gap-3">
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id="calculate-balances"
                                        checked={
                                            balanceColumnSet
                                                ? false
                                                : calculateBalances
                                        }
                                        disabled={balanceColumnSet}
                                        onCheckedChange={(checked) =>
                                            onCalculateBalancesChange(
                                                checked === true,
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                    <Label
                                        htmlFor="calculate-balances"
                                        className={`cursor-pointer font-normal ${balanceColumnSet ? 'opacity-50' : ''}`}
                                    >
                                        {__(
                                            'Work balances out from the transactions instead',
                                        )}
                                    </Label>
                                </div>

                                {effectiveCalculate && latestDate && (
                                    <div className="flex flex-col gap-2 rounded-md border bg-muted/30 p-3">
                                        <Label htmlFor="reference-balance">
                                            {__('Balance on')}{' '}
                                            {formatRelativeDate(
                                                latestDate,
                                                locale,
                                            )}{' '}
                                            <span className="text-destructive">
                                                *
                                            </span>
                                        </Label>
                                        <AmountInput
                                            id="reference-balance"
                                            value={referenceBalance ?? 0}
                                            onChange={onReferenceBalanceChange}
                                            currencyCode={currencyCode}
                                        />
                                        {referenceBalancePrefilled && (
                                            <p className="text-xs text-muted-foreground">
                                                {__(
                                                    'Pre-filled from an existing balance for this date.',
                                                )}
                                            </p>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                    {columnMapping.currency && (
                        <p className="text-xs text-muted-foreground md:pt-2.5">
                            {__(
                                "Rows in another currency won't get a balance.",
                            )}
                        </p>
                    )}
                </div>

                <div className={`${ROW_GRID} border-t px-4 py-3`}>
                    <Label htmlFor="creditor-column" className="md:pt-2.5">
                        {__('Creditor')}
                    </Label>
                    <ColumnSelect
                        id="creditor-column"
                        value={columnMapping.creditor_name}
                        placeholder={__('Select creditor column')}
                        optional
                        columnOptions={columnOptions}
                        onChange={(value) =>
                            onMappingChange('creditor_name', value)
                        }
                    />
                    <p className="text-xs text-muted-foreground md:pt-2.5">
                        {__('Who was paid')}
                    </p>
                </div>

                <div className={`${ROW_GRID} border-t px-4 py-3`}>
                    <Label htmlFor="debtor-column" className="md:pt-2.5">
                        {__('Debtor')}
                    </Label>
                    <ColumnSelect
                        id="debtor-column"
                        value={columnMapping.debtor_name}
                        placeholder={__('Select debtor column')}
                        optional
                        columnOptions={columnOptions}
                        onChange={(value) =>
                            onMappingChange('debtor_name', value)
                        }
                    />
                    <p className="text-xs text-muted-foreground md:pt-2.5">
                        {__('Who paid')}
                    </p>
                </div>
            </CollapsibleSection>

            <div className="flex items-center justify-between pt-2">
                <Button variant="outline" onClick={onBack}>
                    {__('Back')}
                </Button>
                <div className="flex items-center gap-3">
                    {report && (
                        <span className="text-xs text-muted-foreground">
                            {report.readyCount} {__('of')} {report.total}{' '}
                            {__('rows ready')}
                        </span>
                    )}
                    <Button onClick={onNext} disabled={!isValid}>
                        {__('Preview Transactions')}
                    </Button>
                </div>
            </div>
        </div>
    );
}
