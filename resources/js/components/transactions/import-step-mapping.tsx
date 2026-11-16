import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    collectTypeValues,
    detectExpenseTypes,
    isSameTypeValue,
    parseAmount,
    parseDate,
    typeSigner,
} from '@/lib/file-parser';
import {
    DateFormat,
    type ColumnMapping,
    type ColumnOption,
    type ParsedRow,
    type TypeValueCount,
} from '@/types/import';
import { __ } from '@/utils/i18n';
import { useMemo, type ReactNode } from 'react';

const NONE = '__none__';

/** How many distinct type values are offered to tick, most common first. */
const MAX_TYPE_VALUES = 20;

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
    currencyCode: string;
    /** False for a credit card: it has no balance to map. */
    tracksBalance: boolean;
    onMappingChange: (
        field: keyof ColumnMapping,
        value: string | string[],
    ) => void;
    onDateFormatChange: (format: DateFormat) => void;
    onNext: () => void;
    onBack: () => void;
    /** The AI badge or button, shown above the fields. */
    aiDetection?: ReactNode;
}

/**
 * One "map this field to a column" select, shared by the optional fields of
 * this step and by the full import's columns step. `extraOptions` sit above
 * the file's columns, for choices that are not a column (a single-account
 * file).
 */
export function ColumnSelect({
    id,
    value,
    placeholder,
    optional,
    columnOptions,
    extraOptions = [],
    onChange,
}: {
    id: string;
    value: string | null;
    placeholder: string;
    optional?: boolean;
    columnOptions: ColumnOption[];
    extraOptions?: { value: string; label: string }[];
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
            <SelectContent className="ph-no-capture">
                {optional && <SelectItem value={NONE}>{__('None')}</SelectItem>}
                {extraOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
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

/** The date format a file is read with, as a compact select (full import). */
export function DateFormatSelect({
    id,
    value,
    onChange,
    triggerClassName,
    ariaLabel,
}: {
    id?: string;
    value: DateFormat;
    onChange: (format: DateFormat) => void;
    triggerClassName?: string;
    ariaLabel?: string;
}) {
    return (
        <Select
            value={value}
            onValueChange={(next) => onChange(next as DateFormat)}
        >
            <SelectTrigger
                id={id}
                className={triggerClassName}
                aria-label={ariaLabel}
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {Object.entries(DATE_FORMAT_LABELS).map(([format, label]) => (
                    <SelectItem key={format} value={format}>
                        {label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** The type column's values, for the user to tick the ones that are expenses. */
function ExpenseTypePicker({
    values,
    selected,
    onChange,
}: {
    values: TypeValueCount[];
    selected: string[];
    onChange: (selected: string[]) => void;
}) {
    const isTicked = (value: string) =>
        selected.some((one) => isSameTypeValue(one, value));
    // A ticked value stays in sight past the cap: it still turns rows negative,
    // so it must be there to untick.
    const shown = values.filter(
        ({ value }, index) => index < MAX_TYPE_VALUES || isTicked(value),
    );
    const hiddenCount = values.length - shown.length;

    const toggle = (value: string, checked: boolean) => {
        const rest = selected.filter((one) => !isSameTypeValue(one, value));
        onChange(checked ? [...rest, value] : rest);
    };

    return (
        <fieldset className="flex flex-col gap-1.5 pt-2">
            <legend className="pb-1.5 text-xs text-muted-foreground">
                {__('Tick the values that mean money going out')}
            </legend>
            {shown.map(({ value, count }, index) => (
                <div key={value} className="flex items-center gap-2">
                    <Checkbox
                        id={`expense-type-${index}`}
                        checked={isTicked(value)}
                        onCheckedChange={(checked) =>
                            toggle(value, checked === true)
                        }
                    />
                    <Label
                        htmlFor={`expense-type-${index}`}
                        className="cursor-pointer font-normal"
                    >
                        {value}{' '}
                        <span className="text-xs text-muted-foreground">
                            · {__(':count rows', { count })}
                        </span>
                    </Label>
                </div>
            ))}
            {hiddenCount > 0 && (
                <span className="text-xs text-muted-foreground">
                    {__(':count more values not shown', {
                        count: hiddenCount,
                    })}
                </span>
            )}
        </fieldset>
    );
}

export function ImportStepMapping({
    columnOptions,
    columnMapping,
    dateFormat,
    dateFormatDetected,
    parsedData,
    currencyCode,
    tracksBalance,
    onMappingChange,
    onDateFormatChange,
    onNext,
    onBack,
    aiDetection,
}: ImportStepMappingProps) {
    const descriptionColumns = Array.isArray(columnMapping.description)
        ? columnMapping.description
        : columnMapping.description
          ? [columnMapping.description]
          : [];
    const locale = useLocale();

    const baseMappingValid =
        !!columnMapping.transaction_date &&
        !!columnMapping.description &&
        !!columnMapping.amount;

    const typeValues = useMemo(
        () => collectTypeValues(parsedData, columnMapping.transaction_type),
        [parsedData, columnMapping.transaction_type],
    );

    // A new type column brings its own values, so the ticks start over from
    // the ones that plainly mean an expense.
    const handleTypeColumnChange = (column: string) => {
        onMappingChange('transaction_type', column);
        onMappingChange(
            'expense_types',
            detectExpenseTypes(parsedData, column || null),
        );
    };

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

    const signAmount = typeSigner(columnMapping);

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
                          currency: currencyCode,
                      })
                          .format(signAmount(amount, row))
                          .replace(/\s/g, '\u202F')
                    : 'Invalid amount',
        };
    });

    return (
        <div className="flex flex-col gap-6">
            {aiDetection}

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
                        <SelectContent className="ph-no-capture">
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
                            <SelectContent className="ph-no-capture">
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
                                        <SelectContent className="ph-no-capture">
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
                    <ColumnSelect
                        id="amount-column"
                        value={columnMapping.amount}
                        placeholder={__('Select amount column')}
                        columnOptions={columnOptions}
                        onChange={(value) => onMappingChange('amount', value)}
                    />
                </div>

                {tracksBalance && (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="balance-column">
                            {__('Balance (Optional)')}
                        </Label>
                        <ColumnSelect
                            id="balance-column"
                            value={columnMapping.balance}
                            placeholder={__('Select balance column (optional)')}
                            optional
                            columnOptions={columnOptions}
                            onChange={(value) =>
                                onMappingChange('balance', value)
                            }
                        />
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="creditor-column">
                            {__('Creditor name (Optional)')}
                        </Label>
                        <ColumnSelect
                            id="creditor-column"
                            value={columnMapping.creditor_name}
                            placeholder={__(
                                'Select creditor column (optional)',
                            )}
                            optional
                            columnOptions={columnOptions}
                            onChange={(value) =>
                                onMappingChange('creditor_name', value)
                            }
                        />
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="debtor-column">
                            {__('Debtor name (Optional)')}
                        </Label>
                        <ColumnSelect
                            id="debtor-column"
                            value={columnMapping.debtor_name}
                            placeholder={__('Select debtor column (optional)')}
                            optional
                            columnOptions={columnOptions}
                            onChange={(value) =>
                                onMappingChange('debtor_name', value)
                            }
                        />
                    </div>
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="type-column">{__('Type (Optional)')}</Label>
                    <ColumnSelect
                        id="type-column"
                        value={columnMapping.transaction_type ?? null}
                        placeholder={__('Select type column (optional)')}
                        optional
                        columnOptions={columnOptions}
                        onChange={handleTypeColumnChange}
                    />
                    <p className="text-xs text-muted-foreground">
                        {__(
                            'For files whose amounts are all positive: ticked rows import as expenses',
                        )}
                    </p>
                    {typeValues.length > 0 && (
                        <ExpenseTypePicker
                            values={typeValues}
                            selected={columnMapping.expense_types ?? []}
                            onChange={(selected) =>
                                onMappingChange('expense_types', selected)
                            }
                        />
                    )}
                </div>

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
                <Button onClick={onNext} disabled={!baseMappingValid}>
                    {__('Preview Transactions')}
                </Button>
            </div>
        </div>
    );
}
