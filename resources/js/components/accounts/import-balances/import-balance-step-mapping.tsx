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
import { useLocale } from '@/hooks/use-locale';
import { parseAmount, parseDate } from '@/lib/file-parser';
import { balanceTermCapitalized } from '@/types/account';
import type {
    BalanceColumnMapping,
    ColumnOption,
    ParsedRow,
} from '@/types/balance-import';
import { DateFormat } from '@/types/import';
import { __ } from '@/utils/i18n';

interface ImportBalanceStepMappingProps {
    columnOptions: ColumnOption[];
    columnMapping: BalanceColumnMapping;
    dateFormat: DateFormat;
    dateFormatDetected: boolean;
    parsedData: ParsedRow[];
    currencyCode: string;
    investedAmountCurrencyCode: string;
    showInvestedAmount: boolean;
    isLoan?: boolean;
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
    investedAmountCurrencyCode,
    showInvestedAmount,
    isLoan = false,
    onMappingChange,
    onDateFormatChange,
    onNext,
    onBack,
}: ImportBalanceStepMappingProps) {
    const isValid = columnMapping.balance_date && columnMapping.balance;
    const locale = useLocale();

    const formatRawAmount = (value: number) =>
        new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currencyCode,
        })
            .format(value)
            .replace(/\s/g, '\u202F');

    const formatRawInvestedAmount = (value: number) =>
        new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: investedAmountCurrencyCode,
        })
            .format(value)
            .replace(/\s/g, '\u202F');

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

        let investedAmount: string | null = null;
        if (showInvestedAmount && columnMapping.invested_amount) {
            const invested = parseAmount(
                row[columnMapping.invested_amount] as string | number,
            );
            investedAmount =
                invested !== null
                    ? formatRawInvestedAmount(invested)
                    : 'Invalid amount';
        }

        return {
            date: date
                ? date.toLocaleDateString(locale, {
                      year: 'numeric',
                      month: 'short',
                      day: 'numeric',
                  })
                : 'Invalid date',
            balance:
                balance !== null ? formatRawAmount(balance) : 'Invalid amount',
            investedAmount,
        };
    });

    return (
        <div className="flex flex-col gap-6">
            <div className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="date-column">
                        {__('Balance Date')}
                        <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.balance_date || ''}
                        onValueChange={(value) =>
                            onMappingChange('balance_date', value)
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

                <div className="space-y-2">
                    <Label htmlFor="balance-column">
                        {balanceTermCapitalized(isLoan ? 'loan' : 'checking')}
                        <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={columnMapping.balance || ''}
                        onValueChange={(value) =>
                            onMappingChange('balance', value)
                        }
                    >
                        <SelectTrigger id="balance-column">
                            <SelectValue
                                placeholder={
                                    isLoan
                                        ? __('Select owed amount column')
                                        : __('Select balance column')
                                }
                            />
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

                {showInvestedAmount && (
                    <div className="space-y-2">
                        <Label htmlFor="invested-column">
                            {__('Invested Amount')}
                        </Label>
                        <Select
                            value={columnMapping.invested_amount || ''}
                            onValueChange={(value) =>
                                onMappingChange('invested_amount', value)
                            }
                        >
                            <SelectTrigger id="invested-column">
                                <SelectValue
                                    placeholder={__(
                                        'Select invested amount column (optional)',
                                    )}
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {columnOptions.map((option, index) => (
                                    <SelectItem
                                        key={`invested-${option.value}-${index}`}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}

                {isValid && previewBalances.length > 0 && (
                    <div className="space-y-4 rounded-lg border bg-muted/30 p-4">
                        <Label className="pl-2 text-xs font-light tracking-widest uppercase opacity-50">
                            {__('Preview (first 3 rows)')}
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
                                    <div className="flex items-center gap-3">
                                        {balance.investedAmount && (
                                            <span className="font-mono whitespace-nowrap text-muted-foreground">
                                                {balance.investedAmount}
                                            </span>
                                        )}
                                        <span className="font-mono font-medium whitespace-nowrap">
                                            {balance.balance}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {!dateFormatDetected && (
                    <div className="space-y-3 rounded-lg border p-4">
                        <Label>{__('Date Format')}</Label>
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
                                    {__('YYYY-MM-DD (e.g., 2024-12-31)')}
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
                                    {__('MM-DD-YYYY (e.g., 12-31-2024)')}
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
                                    {__('DD-MM-YYYY (e.g., 31-12-2024)')}
                                </Label>
                            </div>
                            <div className="flex items-center space-x-2">
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
            </div>

            <div className="flex justify-between">
                <Button variant="outline" onClick={onBack}>
                    {__('Back')}
                </Button>
                <Button onClick={onNext} disabled={!isValid}>
                    {isLoan
                        ? __('Preview Owed Amounts')
                        : __('Preview Balances')}
                </Button>
            </div>
        </div>
    );
}
