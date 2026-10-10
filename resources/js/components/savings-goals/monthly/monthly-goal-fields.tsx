import { AmountInput } from '@/components/ui/amount-input';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label as UILabel } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { AutoTagAccount, MonthlyTargetType } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';

export interface MonthlyTargetValue {
    type: MonthlyTargetType;
    amount: number;
    /** As typed, so "12." can be on its way to "12.5". */
    rate: string;
}

/** What the create and edit dialogs send for a monthly target. */
export function monthlyTargetPayload(value: MonthlyTargetValue) {
    return {
        monthly_target_type: value.type,
        monthly_target_amount: value.type === 'amount' ? value.amount : null,
        monthly_target_rate:
            value.type === 'income_rate' ? Number(value.rate) : null,
    };
}

export function isMonthlyTargetValid(value: MonthlyTargetValue): boolean {
    if (value.type === 'amount') {
        return value.amount > 0;
    }

    const rate = Number(value.rate);

    return value.rate !== '' && rate > 0 && rate <= 100;
}

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

interface MonthlyTargetFieldsProps {
    idPrefix: string;
    value: MonthlyTargetValue;
    onChange: (value: MonthlyTargetValue) => void;
    currencyCode: string;
    errors: Record<string, string>;
}

/**
 * "I want to save every month": a fixed amount, or a share of income.
 */
export function MonthlyTargetFields({
    idPrefix,
    value,
    onChange,
    currencyCode,
    errors,
}: MonthlyTargetFieldsProps) {
    return (
        <div className="space-y-2">
            <UILabel htmlFor={`${idPrefix}-monthly-target`}>
                {__('I want to save every month')}
            </UILabel>
            <div className="flex gap-2">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={value.type}
                    onValueChange={(type) =>
                        type &&
                        onChange({ ...value, type: type as MonthlyTargetType })
                    }
                    aria-label={__('Target type')}
                >
                    <ToggleGroupItem value="amount" className="px-3">
                        {__('Fixed amount')}
                    </ToggleGroupItem>
                    <ToggleGroupItem value="income_rate" className="px-3">
                        {__('% of income')}
                    </ToggleGroupItem>
                </ToggleGroup>
                <div className="min-w-0 flex-1">
                    {value.type === 'amount' ? (
                        <AmountInput
                            id={`${idPrefix}-monthly-target`}
                            value={value.amount}
                            onChange={(amount) =>
                                onChange({ ...value, amount })
                            }
                            currencyCode={currencyCode}
                        />
                    ) : (
                        <div className="relative">
                            <Input
                                id={`${idPrefix}-monthly-target`}
                                type="number"
                                inputMode="decimal"
                                min={0.01}
                                max={100}
                                step={0.01}
                                value={value.rate}
                                onChange={(event) =>
                                    onChange({
                                        ...value,
                                        rate: event.target.value,
                                    })
                                }
                                className="pr-8 text-right tabular-nums"
                            />
                            <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                                %
                            </span>
                        </div>
                    )}
                </div>
            </div>
            {value.type === 'income_rate' && (
                <p className="text-sm text-muted-foreground">
                    {__(
                        'Worked out from your average income over the previous three months, and fixed when the month starts.',
                    )}
                </p>
            )}
            <FieldError message={errors.monthly_target_type} />
            <FieldError
                message={
                    errors.monthly_target_amount ?? errors.monthly_target_rate
                }
            />
        </div>
    );
}

interface ReminderFieldProps {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}

export function ReminderField({ id, checked, onChange }: ReminderFieldProps) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(state) => onChange(state === true)}
                className="mt-0.5"
            />
            <UILabel htmlFor={id} className="flex flex-col items-start gap-1">
                <span>{__('Month-end reminder')}</span>
                <span className="text-sm font-normal text-muted-foreground">
                    {__(
                        "An email 5 days before the month ends if you haven't met it yet.",
                    )}
                </span>
            </UILabel>
        </div>
    );
}

interface AutoTagFieldsProps {
    enabled: boolean;
    onEnabledChange: (enabled: boolean) => void;
    accountId: string;
    onAccountChange: (accountId: string) => void;
    savingsAccounts: AutoTagAccount[];
    error?: string;
}

/**
 * Offers a rule that tags every transfer into a savings account with the
 * goal's label, so contributions count on their own. Only savings accounts are
 * offered: on any other type the money arriving would count against the goal.
 */
export function AutoTagFields({
    enabled,
    onEnabledChange,
    accountId,
    onAccountChange,
    savingsAccounts,
    error,
}: AutoTagFieldsProps) {
    if (savingsAccounts.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3 rounded-lg border p-4">
            <div className="flex items-start gap-3">
                <Checkbox
                    id="goal-auto-tag"
                    checked={enabled}
                    onCheckedChange={(state) => onEnabledChange(state === true)}
                    className="mt-0.5"
                />
                <UILabel
                    htmlFor="goal-auto-tag"
                    className="flex flex-col items-start gap-1"
                >
                    <span>{__('Tag contributions automatically')}</span>
                    <span className="text-sm font-normal text-muted-foreground">
                        {__(
                            'Tags every incoming transaction into the account from the 1st of this month, interest and refunds included, and creates an automation rule you can edit in Settings › Automation rules.',
                        )}
                    </span>
                </UILabel>
            </div>
            {enabled && (
                <div className="flex flex-col gap-2 pl-7">
                    <UILabel
                        htmlFor="goal-auto-tag-account"
                        className="font-normal text-muted-foreground"
                    >
                        {__('Transfers into')}
                    </UILabel>
                    <Select value={accountId} onValueChange={onAccountChange}>
                        <SelectTrigger id="goal-auto-tag-account">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {savingsAccounts.map((account) => (
                                <SelectItem key={account.id} value={account.id}>
                                    {account.bank
                                        ? `${account.name} · ${account.bank.name}`
                                        : account.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldError message={error} />
                </div>
            )}
        </div>
    );
}
