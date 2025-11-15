import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { parseAmount, parseDate } from '@/lib/file-parser';
import {
    DateFormat,
    type ColumnMapping,
    type ColumnOption,
    type ParsedRow,
} from '@/types/import';

interface ImportStepMappingProps {
    columnOptions: ColumnOption[];
    columnMapping: ColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    parsedData: ParsedRow[];
    currencyCode: string;
    onMappingChange: (field: keyof ColumnMapping, value: string) => void;
    onDateFormatChange: (format: DateFormat) => void;
    onNext: () => void;
    onBack: () => void;
}

export function ImportStepMapping({
    columnOptions,
    columnMapping,
    dateFormat,
    dateFormatDetected,
    parsedData,
    currencyCode,
    onMappingChange,
    onDateFormatChange,
    onNext,
    onBack,
}: ImportStepMappingProps) {
    const isValid =
        columnMapping.transaction_date &&
        columnMapping.description &&
        columnMapping.amount;

    const previewTransactions = parsedData.slice(0, 3).map((row) => {
        const date = columnMapping.transaction_date
            ? parseDate(
                  row[columnMapping.transaction_date] as string | number,
                  dateFormat,
              )
            : null;
        const description = columnMapping.description
            ? String(row[columnMapping.description] || '')
            : '';
        const amount = columnMapping.amount
            ? parseAmount(row[columnMapping.amount] as string | number)
            : null;

        return {
            date: date
                ? date.toLocaleDateString('en-US', {
                      year: 'numeric',
                      month: 'short',
                      day: 'numeric',
                  })
                : 'Invalid date',
            description: description || 'No description',
            amount:
                amount !== null
                    ? new Intl.NumberFormat('en-US', {
                          style: 'currency',
                          currency: currencyCode,
                      }).format(amount)
                    : 'Invalid amount',
        };
    });

    return (
        <div className="flex flex-col gap-6">
            <div className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="date-column">
                        Transaction Date{' '}
                        <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.transaction_date || ''}
                        onValueChange={(value) =>
                            onMappingChange('transaction_date', value)
                        }
                    >
                        <SelectTrigger id="date-column">
                            <SelectValue placeholder="Select date column" />
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

                <div className="space-y-2">
                    <Label htmlFor="description-column">
                        Description <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.description || ''}
                        onValueChange={(value) =>
                            onMappingChange('description', value)
                        }
                    >
                        <SelectTrigger id="description-column">
                            <SelectValue placeholder="Select description column" />
                        </SelectTrigger>
                        <SelectContent>
                            {columnOptions.map((option, index) => (
                                <SelectItem
                                    key={`desc-${option.value}-${index}`}
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

                <div className="space-y-2">
                    <Label htmlFor="amount-column">
                        Amount <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.amount || ''}
                        onValueChange={(value) =>
                            onMappingChange('amount', value)
                        }
                    >
                        <SelectTrigger id="amount-column">
                            <SelectValue placeholder="Select amount column" />
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

                <div className="space-y-2">
                    <Label htmlFor="balance-column">
                        Balance (Optional)
                    </Label>
                    <Select
                        value={columnMapping.balance || '__none__'}
                        onValueChange={(value) =>
                            onMappingChange('balance', value === '__none__' ? '' : value)
                        }
                    >
                        <SelectTrigger id="balance-column">
                            <SelectValue placeholder="Select balance column (optional)" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">None</SelectItem>
                            {columnOptions.map((option, index) => (
                                <SelectItem
                                    key={`balance-${option.value}-${index}`}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {isValid && previewTransactions.length > 0 && (
                    <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                        <Label className="pl-2 text-xs font-light tracking-widest uppercase opacity-50">
                            Preview (first 3 rows)
                        </Label>
                        <div className="space-y-2 pt-2">
                            {previewTransactions.map((transaction, index) => (
                                <div
                                    key={index}
                                    className="flex items-center justify-between rounded-md bg-background p-3 text-sm"
                                >
                                    <div className="flex flex-1 items-center gap-3">
                                        <span className="text-muted-foreground">
                                            {transaction.date}
                                        </span>
                                        <span className="flex-1 truncate">
                                            {transaction.description}
                                        </span>
                                    </div>
                                    <span className="font-mono font-medium">
                                        {transaction.amount}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {!dateFormatDetected && (
                    <div className="space-y-3 rounded-lg border p-4">
                        <Label>Date Format</Label>
                        <RadioGroup
                            value={dateFormat}
                            onValueChange={(value) =>
                                onDateFormatChange(value as DateFormat)
                            }
                        >
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem
                                    value={DateFormat.YearMonthDay}
                                    id="format-ymd"
                                />
                                <Label
                                    htmlFor="format-ymd"
                                    className="cursor-pointer font-normal"
                                >
                                    YYYY-MM-DD (e.g., 2024-12-31)
                                </Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem
                                    value={DateFormat.MonthDayYear}
                                    id="format-mdy"
                                />
                                <Label
                                    htmlFor="format-mdy"
                                    className="cursor-pointer font-normal"
                                >
                                    MM-DD-YYYY (e.g., 12-31-2024)
                                </Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem
                                    value={DateFormat.DayMonthYear}
                                    id="format-dmy"
                                />
                                <Label
                                    htmlFor="format-dmy"
                                    className="cursor-pointer font-normal"
                                >
                                    DD-MM-YYYY (e.g., 31-12-2024)
                                </Label>
                            </div>
                        </RadioGroup>
                    </div>
                )}
            </div>

            <div className="flex justify-between">
                <Button variant="outline" onClick={onBack}>
                    Back
                </Button>
                <Button onClick={onNext} disabled={!isValid}>
                    Preview Transactions
                </Button>
            </div>
        </div>
    );
}
