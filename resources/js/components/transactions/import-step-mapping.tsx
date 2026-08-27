import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useLocale } from '@/hooks/use-locale';
import {
    collectCurrencyCodes,
    getLatestTransactionDate,
    parseAmount,
    parseCurrencyCode,
    parseDate,
} from '@/lib/file-parser';
import {
    DateFormat,
    type ColumnMapping,
    type ColumnOption,
    type ParsedRow,
} from '@/types/import';
import { formatRelativeDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { ChevronDown } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface ImportStepMappingProps {
    columnOptions: ColumnOption[];
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    parsedData: ParsedRow[];
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

/**
 * A "map this field to a column, or leave it out" select. Shared by every
 * optional field so they stay identical.
 */
function OptionalColumnSelect({
    id,
    label,
    placeholder,
    value,
    columnOptions,
    onChange,
}: {
    id: string;
    label: string;
    placeholder: string;
    value: string | null;
    columnOptions: ColumnOption[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Select
                value={value || '__none__'}
                onValueChange={(next) =>
                    onChange(next === '__none__' ? '' : next)
                }
            >
                <SelectTrigger id={id}>
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="__none__">{__('None')}</SelectItem>
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
        </div>
    );
}

export function ImportStepMapping({
    columnOptions,
    columnMapping,
    dateFormat,
    dateFormatDetected,
    parsedData,
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
    const descriptionColumns = Array.isArray(columnMapping.description)
        ? columnMapping.description
        : columnMapping.description
          ? [columnMapping.description]
          : [];
    const locale = useLocale();

    const balanceColumnSet = !!columnMapping.balance;
    const checkboxDisabled = balanceColumnSet;
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

    const detectedCurrencies = useMemo(
        () =>
            collectCurrencyCodes(
                parsedData,
                columnMapping.currency,
                supportedCurrencies,
            ),
        [parsedData, columnMapping.currency, supportedCurrencies],
    );

    // Balances are the account's own, held in its currency, so rows in another
    // currency get none: worth saying out loud next to the balance controls.
    const hasForeignCurrency = detectedCurrencies.supported.some(
        (code) => code !== currencyCode,
    );

    // Only date, description and amount are asked for up front. The optional
    // fields stay folded away unless one is already mapped (auto-detected or
    // restored from a saved config), which can happen after mount.
    const hasOptionalMapping =
        !!columnMapping.currency ||
        !!columnMapping.balance ||
        !!columnMapping.creditor_name ||
        !!columnMapping.debtor_name;
    const [optionalToggled, setOptionalToggled] = useState<boolean | null>(
        null,
    );
    const optionalOpen = optionalToggled ?? hasOptionalMapping;

    const baseMappingValid =
        !!columnMapping.transaction_date &&
        !!columnMapping.description &&
        !!columnMapping.amount;

    const isValid =
        baseMappingValid &&
        (!effectiveCalculate ||
            (latestDate !== null &&
                referenceBalance !== null &&
                referenceBalance !== undefined));

    const getDescriptionFromRow = (row: ParsedRow): string => {
        if (!columnMapping.description) {
            return '';
        }

        const columns = Array.isArray(columnMapping.description)
            ? columnMapping.description
            : [columnMapping.description];

        return columns
            .map((col) => String(row[col] || '').trim())
            .filter((val) => val.length > 0)
            .join('\n');
    };

    const handleDescriptionChange = (index: number, value: string) => {
        const newColumns = [...descriptionColumns];

        if (value === '__none__') {
            newColumns.splice(index, 1);
        } else {
            newColumns[index] = value;
        }

        if (newColumns.length === 0) {
            onMappingChange('description', '');
        } else if (newColumns.length === 1) {
            onMappingChange('description', newColumns[0]);
        } else {
            onMappingChange('description', newColumns);
        }
    };

    const addDescriptionColumn = () => {
        if (descriptionColumns.length < 3) {
            const newColumns = [...descriptionColumns, ''];
            if (newColumns.length === 1) {
                onMappingChange('description', '');
            } else {
                onMappingChange('description', newColumns);
            }
        }
    };

    const previewTransactions = parsedData.slice(0, 3).map((row) => {
        const date = columnMapping.transaction_date
            ? parseDate(
                  row[columnMapping.transaction_date] as string | number,
                  dateFormat,
              )
            : null;
        const description = getDescriptionFromRow(row);
        const amount = columnMapping.amount
            ? parseAmount(row[columnMapping.amount] as string | number)
            : null;
        const rowCurrency = columnMapping.currency
            ? (parseCurrencyCode(
                  row[columnMapping.currency],
                  supportedCurrencies,
              ) ?? currencyCode)
            : currencyCode;

        return {
            date: date
                ? date.toLocaleDateString(locale, {
                      year: 'numeric',
                      month: 'short',
                      day: 'numeric',
                  })
                : 'Invalid date',
            description: description || 'No description',
            amount:
                amount !== null
                    ? new Intl.NumberFormat(locale, {
                          style: 'currency',
                          currency: rowCurrency,
                      })
                          .format(amount)
                          .replace(/\s/g, '\u202F')
                    : 'Invalid amount',
        };
    });

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <Label htmlFor="date-column">
                        {__('Transaction Date')}{' '}
                        <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.transaction_date || ''}
                        onValueChange={(value) =>
                            onMappingChange('transaction_date', value)
                        }
                    >
                        <SelectTrigger id="date-column">
                            <SelectValue
                                placeholder={__('Select date column')}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {columnOptions.map((option, index) => (
                                <SelectItem
                                    key={`date-${option.value}-${index}`}
                                    value={option.value}
                                >
                                    <div className="flex flex-col">
                                        <span>{option.label}</span>
                                    </div>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="description-column-0">
                        {__('Description')}
                        <span className="text-destructive">*</span>
                    </Label>
                    {descriptionColumns.length === 0 ? (
                        <Select
                            value=""
                            onValueChange={(value) =>
                                handleDescriptionChange(0, value)
                            }
                        >
                            <SelectTrigger id="description-column-0">
                                <SelectValue
                                    placeholder={__(
                                        'Select description column',
                                    )}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {columnOptions.map((option, index) => (
                                    <SelectItem
                                        key={`desc-0-${option.value}-${index}`}
                                        value={option.value}
                                    >
                                        <div className="flex flex-col">
                                            <span>{option.label}</span>
                                        </div>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {descriptionColumns.map((column, columnIndex) => (
                                <div
                                    key={columnIndex}
                                    className={columnIndex > 0 ? 'pl-4' : ''}
                                >
                                    <Select
                                        value={column || ''}
                                        onValueChange={(value) =>
                                            handleDescriptionChange(
                                                columnIndex,
                                                value,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id={`description-column-${columnIndex}`}
                                        >
                                            <SelectValue
                                                placeholder={
                                                    columnIndex === 0
                                                        ? 'Select description column'
                                                        : 'Select additional column'
                                                }
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {columnIndex > 0 && (
                                                <SelectItem value="__none__">
                                                    {__('None (Remove)')}
                                                </SelectItem>
                                            )}
                                            {columnOptions.map(
                                                (option, index) => (
                                                    <SelectItem
                                                        key={`desc-${columnIndex}-${option.value}-${index}`}
                                                        value={option.value}
                                                    >
                                                        <div className="flex flex-col">
                                                            <span>
                                                                {option.label}
                                                            </span>
                                                        </div>
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ))}
                            {descriptionColumns.length > 0 &&
                                descriptionColumns.length < 3 &&
                                descriptionColumns[
                                    descriptionColumns.length - 1
                                ] !== '' && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={addDescriptionColumn}
                                        className="ml-4"
                                    >
                                        {__('+ Add another column')}
                                    </Button>
                                )}
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="amount-column">
                        {__('Amount')}
                        <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.amount || ''}
                        onValueChange={(value) =>
                            onMappingChange('amount', value)
                        }
                    >
                        <SelectTrigger id="amount-column">
                            <SelectValue
                                placeholder={__('Select amount column')}
                            />
                        </SelectTrigger>
                        <SelectContent>
                            {columnOptions.map((option, index) => (
                                <SelectItem
                                    key={`amount-${option.value}-${index}`}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <Collapsible
                    open={optionalOpen}
                    onOpenChange={setOptionalToggled}
                    className="rounded-lg border border-sidebar-border bg-sidebar p-1"
                >
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            className="flex w-full cursor-pointer items-center justify-between hover:bg-transparent"
                        >
                            <span className="text-sm text-muted-foreground">
                                {__('Optional fields')}
                            </span>
                            <ChevronDown
                                className={`h-4 w-4 text-muted-foreground transition-transform duration-200 ${
                                    optionalOpen ? 'rotate-180' : ''
                                }`}
                            />
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div className="flex flex-col gap-4 p-3 pt-2">
                            <div className="flex flex-col gap-2">
                                <OptionalColumnSelect
                                    id="currency-column"
                                    label={__('Currency')}
                                    placeholder={__(
                                        'Select currency column (optional)',
                                    )}
                                    value={columnMapping.currency}
                                    columnOptions={columnOptions}
                                    onChange={(value) =>
                                        onMappingChange('currency', value)
                                    }
                                />

                                {!columnMapping.currency ? (
                                    <p className="text-xs text-muted-foreground">
                                        {__(
                                            'Every transaction will use the account currency',
                                        )}{' '}
                                        ({currencyCode}).
                                    </p>
                                ) : (
                                    <>
                                        {detectedCurrencies.supported.length >
                                            0 && (
                                            <p className="text-xs text-muted-foreground">
                                                {__('Currencies found:')}{' '}
                                                {detectedCurrencies.supported.join(
                                                    ', ',
                                                )}
                                            </p>
                                        )}
                                        {detectedCurrencies.unsupported.length >
                                            0 && (
                                            <p className="text-xs text-amber-600 dark:text-amber-400">
                                                {__('Not supported yet:')}{' '}
                                                {detectedCurrencies.unsupported.join(
                                                    ', ',
                                                )}
                                                .{' '}
                                                {__(
                                                    'Those rows will use the account currency',
                                                )}{' '}
                                                ({currencyCode}).
                                            </p>
                                        )}
                                    </>
                                )}
                            </div>

                            <div className="flex flex-col gap-2">
                                <OptionalColumnSelect
                                    id="balance-column"
                                    label={__('Balance')}
                                    placeholder={__(
                                        'Select balance column (optional)',
                                    )}
                                    value={columnMapping.balance}
                                    columnOptions={columnOptions}
                                    onChange={(value) =>
                                        onMappingChange('balance', value)
                                    }
                                />

                                {hasForeignCurrency && (
                                    <p className="text-xs text-muted-foreground">
                                        {__(
                                            "Rows in another currency won't get a balance.",
                                        )}
                                    </p>
                                )}

                                {calculateBalancesAvailable && (
                                    <div className="flex flex-col gap-3 pt-2">
                                        <div className="flex items-start gap-2">
                                            <Checkbox
                                                id="calculate-balances"
                                                checked={
                                                    balanceColumnSet
                                                        ? false
                                                        : calculateBalances
                                                }
                                                disabled={checkboxDisabled}
                                                onCheckedChange={(checked) =>
                                                    onCalculateBalancesChange(
                                                        checked === true,
                                                    )
                                                }
                                                className="mt-0.5"
                                            />
                                            <div className="flex flex-col gap-1">
                                                <Label
                                                    htmlFor="calculate-balances"
                                                    className={`cursor-pointer font-normal ${checkboxDisabled ? 'opacity-50' : ''}`}
                                                >
                                                    {__(
                                                        'Calculate balances from transactions',
                                                    )}
                                                </Label>
                                                <p className="text-xs text-muted-foreground">
                                                    {__(
                                                        'Use the balance on the latest transaction date as a reference to compute balances for older dates.',
                                                    )}
                                                </p>
                                            </div>
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
                                                    value={
                                                        referenceBalance ?? 0
                                                    }
                                                    onChange={
                                                        onReferenceBalanceChange
                                                    }
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

                            <div className="grid gap-4 md:grid-cols-2">
                                <OptionalColumnSelect
                                    id="creditor-column"
                                    label={__('Creditor name')}
                                    placeholder={__(
                                        'Select creditor column (optional)',
                                    )}
                                    value={columnMapping.creditor_name}
                                    columnOptions={columnOptions}
                                    onChange={(value) =>
                                        onMappingChange('creditor_name', value)
                                    }
                                />

                                <OptionalColumnSelect
                                    id="debtor-column"
                                    label={__('Debtor name')}
                                    placeholder={__(
                                        'Select debtor column (optional)',
                                    )}
                                    value={columnMapping.debtor_name}
                                    columnOptions={columnOptions}
                                    onChange={(value) =>
                                        onMappingChange('debtor_name', value)
                                    }
                                />
                            </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>

                {!dateFormatDetected && (
                    <div className="flex flex-col gap-3 rounded-lg border p-4">
                        <Label>{__('Date Format')}</Label>
                        <RadioGroup
                            value={dateFormat}
                            onValueChange={(value) =>
                                onDateFormatChange(value as DateFormat)
                            }
                        >
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    value={DateFormat.YearMonthDay}
                                    id="format-ymd"
                                />

                                <Label
                                    htmlFor="format-ymd"
                                    className="cursor-pointer font-normal"
                                >
                                    {__('YYYY-MM-DD (e.g., 2024-12-31)')}
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    value={DateFormat.MonthDayYear}
                                    id="format-mdy"
                                />

                                <Label
                                    htmlFor="format-mdy"
                                    className="cursor-pointer font-normal"
                                >
                                    {__('MM-DD-YYYY (e.g., 12-31-2024)')}
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    value={DateFormat.DayMonthYear}
                                    id="format-dmy"
                                />

                                <Label
                                    htmlFor="format-dmy"
                                    className="cursor-pointer font-normal"
                                >
                                    {__('DD-MM-YYYY (e.g., 31-12-2024)')}
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <RadioGroupItem
                                    value={DateFormat.YearMonthDayCompact}
                                    id="format-ymd-compact"
                                />

                                <Label
                                    htmlFor="format-ymd-compact"
                                    className="cursor-pointer font-normal"
                                >
                                    {__('YYYYMMDD (e.g., 20241231)')}
                                </Label>
                            </div>
                        </RadioGroup>
                    </div>
                )}

                {baseMappingValid && previewTransactions.length > 0 && (
                    <div className="flex flex-col gap-4 rounded-lg border bg-muted/30 p-4">
                        <Label className="pl-2 text-xs font-light tracking-widest uppercase opacity-50">
                            {__('Preview (first 3 rows)')}
                        </Label>
                        <div className="flex flex-col gap-2 pt-2">
                            {previewTransactions.map((transaction, index) => (
                                <div
                                    key={index}
                                    className="flex items-start justify-between gap-3 rounded-md bg-background p-3 text-sm"
                                >
                                    <div className="flex flex-1 items-start gap-3">
                                        <span className="whitespace-nowrap text-muted-foreground">
                                            {transaction.date}
                                        </span>
                                        <span className="flex-1 whitespace-pre-line">
                                            {transaction.description}
                                        </span>
                                    </div>
                                    <span className="font-mono font-medium whitespace-nowrap">
                                        {transaction.amount}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <div className="flex justify-between">
                <Button variant="outline" onClick={onBack}>
                    {__('Back')}
                </Button>
                <Button onClick={onNext} disabled={!isValid}>
                    {__('Preview Transactions')}
                </Button>
            </div>
        </div>
    );
}
