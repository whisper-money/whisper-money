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
import type {
    BalanceColumnMapping,
    ColumnOption,
    ParsedRow,
} from '@/types/balance-import';
import { DateFormat } from '@/types/import';

interface ImportBalanceStepMappingProps {
    columnOptions: ColumnOption[];
    columnMapping: BalanceColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    parsedData: ParsedRow[];
    currencyCode: string;
    onMappingChange: (field: keyof BalanceColumnMapping, value: string) => void;
    onDateFormatChange: (format: DateFormat) => void;
    onNext: () => void;
    onBack: () => void;
}

export function ImportBalanceStepMapping({
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
}: ImportBalanceStepMappingProps) {
    const isValid = columnMapping.balance_date && columnMapping.balance;

    const previewBalances = parsedData.slice(0, 3).map((row) => {
        const date = columnMapping.balance_date
            ? parseDate(
                  row[columnMapping.balance_date] as string | number,
                  dateFormat,
              )
            : null;
        const balance = columnMapping.balance
            ? parseAmount(row[columnMapping.balance] as string | number)
            : null;

        return {
            date: date
                ? date.toLocaleDateString('en-US', {
                      year: 'numeric',
                      month: 'short',
                      day: 'numeric',
                  })
                : 'Invalid date',
            balance:
                balance !== null
                    ? new Intl.NumberFormat('en-US', {
                          style: 'currency',
                          currency: currencyCode,
                      }).format(balance)
                    : 'Invalid amount',
        };
    });

    return (
        <div className="flex flex-col gap-6">
            <div className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="date-column">
                        Balance Date <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.balance_date || ''}
                        onValueChange={(value) =>
                            onMappingChange('balance_date', value)
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
                    <Label htmlFor="balance-column">
                        Balance <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.balance || ''}
                        onValueChange={(value) =>
                            onMappingChange('balance', value)
                        }
                    >
                        <SelectTrigger id="balance-column">
                            <SelectValue placeholder="Select balance column" />
                        </SelectTrigger>
                        <SelectContent>
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

                {isValid && previewBalances.length > 0 && (
                    <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                        <Label className="pl-2 text-xs font-light tracking-widest uppercase opacity-50">
                            Preview (first 3 rows)
                        </Label>
                        <div className="space-y-2 pt-2">
                            {previewBalances.map((balance, index) => (
                                <div
                                    key={index}
                                    className="flex items-center justify-between gap-3 rounded-md bg-background p-3 text-sm"
                                >
                                    <span className="whitespace-nowrap text-muted-foreground">
                                        {balance.date}
                                    </span>
                                    <span className="font-mono font-medium whitespace-nowrap">
                                        {balance.balance}
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
                    Preview Balances
                </Button>
            </div>
        </div>
    );
}
