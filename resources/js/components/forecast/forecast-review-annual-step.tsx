import {
    ReviewSectionTitle,
    ReviewStepLayout,
} from '@/components/forecast/forecast-review-layout';
import { AmountDisplay } from '@/components/ui/amount-display';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import {
    annualEvidence,
    type ManualPaymentDraft,
    QUICK_ADDS,
    type QuickAdd,
    type ReviewError,
} from '@/lib/forecast/review';
import { cn } from '@/lib/utils';
import {
    type ForecastAnnualSuggestion,
    type ForecastReview,
} from '@/types/forecast';
import { formatMonthName } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Check, Plus, X } from 'lucide-react';

interface AnnualStepProps {
    review: ForecastReview;
    added: Set<string>;
    onAddedChange: (added: Set<string>) => void;
    manual: ManualPaymentDraft[];
    onManualChange: (manual: ManualPaymentDraft[]) => void;
    defaultAccountId: string | null;
    processing: boolean;
    error: ReviewError | null;
    isPro: boolean;
    onReload: () => void;
    onBack: () => void;
    onFinish: () => void;
}

const MONTHS = Array.from({ length: 12 }, (_, index) => index + 1);

function monthKey(month: number): string {
    return `2000-${String(month).padStart(2, '0')}`;
}

let nextId = 0;

/**
 * Step 2 of the review: the payments that come once or twice a year. The
 * yearly charges the history shows are offered first, with the charges they
 * stand on; then the usual ones as shortcuts, each adding a row with its
 * amount, month and account.
 */
export function ForecastReviewAnnualStep({
    review,
    added,
    onAddedChange,
    manual,
    onManualChange,
    defaultAccountId,
    processing,
    error,
    isPro,
    onReload,
    onBack,
    onFinish,
}: AnnualStepProps) {
    const addedPresets = new Set(manual.map((payment) => payment.presetId));
    const toggleSuggestion = (key: string) => {
        const next = new Set(added);

        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }

        onAddedChange(next);
    };
    const togglePreset = (preset: QuickAdd) => {
        if (preset.id !== 'other' && addedPresets.has(preset.id)) {
            onManualChange(
                manual.filter((payment) => payment.presetId !== preset.id),
            );

            return;
        }

        onManualChange([
            ...manual,
            {
                id: `manual-${nextId++}`,
                presetId: preset.id,
                name: preset.id === 'other' ? '' : preset.label(),
                amount: 0,
                direction: preset.direction,
                month: preset.month,
                accountId: defaultAccountId ?? '',
                frequency: preset.frequency,
            },
        ]);
    };
    const updatePayment = (id: string, change: Partial<ManualPaymentDraft>) =>
        onManualChange(
            manual.map((payment) =>
                payment.id === id ? { ...payment, ...change } : payment,
            ),
        );

    return (
        <ReviewStepLayout
            eyebrow={__('Step 2 of 2 · Forecast')}
            title={__('What do you pay once or twice a year?')}
            description={__(
                'They are few payments, but they are what throws a forecast off the most: they do not show in your last months and we cannot guess them. Add them now and they will not catch you by surprise.',
            )}
            footer={
                <>
                    <Button variant="link" className="px-0" onClick={onBack}>
                        {__('Back')}
                    </Button>
                    <div className="flex flex-col items-end gap-1">
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={onFinish}
                                disabled={processing}
                                data-test="forecast-review-none"
                            >
                                {__('I have no more')}
                            </Button>
                            <Button
                                onClick={onFinish}
                                disabled={processing}
                                data-test="forecast-review-finish"
                            >
                                {__('See my forecast')}
                            </Button>
                        </div>
                        {error && (
                            <span
                                className="flex flex-wrap items-center justify-end gap-x-2 text-xs text-destructive"
                                role="alert"
                            >
                                {error.message}
                                {error.stale && (
                                    <Button
                                        variant="link"
                                        size="sm"
                                        className="h-auto p-0 text-xs"
                                        onClick={onReload}
                                    >
                                        {__('Load them again')}
                                    </Button>
                                )}
                            </span>
                        )}
                    </div>
                </>
            }
        >
            <div className="flex flex-col gap-5 pt-2">
                {review.annual.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <ReviewSectionTitle>
                            {__('We saw it in your movements')}
                        </ReviewSectionTitle>
                        {review.annual.map((suggestion) => (
                            <AnnualSuggestionRow
                                key={suggestion.key}
                                suggestion={suggestion}
                                added={added.has(suggestion.key)}
                                onToggle={() =>
                                    toggleSuggestion(suggestion.key)
                                }
                            />
                        ))}
                    </section>
                )}
                <section className="flex flex-col gap-2.5">
                    <ReviewSectionTitle>
                        {__('The most usual')}
                    </ReviewSectionTitle>
                    <div className="flex flex-wrap gap-2">
                        {QUICK_ADDS.map((preset) => {
                            const on =
                                preset.id !== 'other' &&
                                addedPresets.has(preset.id);

                            return (
                                <Button
                                    key={preset.id}
                                    variant={on ? 'secondary' : 'outline'}
                                    size="sm"
                                    className={cn(
                                        'rounded-full',
                                        on && 'text-muted-foreground',
                                    )}
                                    aria-pressed={on}
                                    onClick={() => togglePreset(preset)}
                                    data-test={`forecast-review-preset-${preset.id}`}
                                >
                                    {on ? <Check /> : <Plus />}
                                    {preset.label()}
                                </Button>
                            );
                        })}
                    </div>
                </section>
                <section className="flex flex-col gap-2">
                    <ReviewSectionTitle>
                        {__('Added (:count)', {
                            count: manual.length + added.size,
                        })}
                    </ReviewSectionTitle>
                    {manual.map((payment) => (
                        <ManualPaymentRow
                            key={payment.id}
                            payment={payment}
                            accounts={review.accounts}
                            onChange={(change) =>
                                updatePayment(payment.id, change)
                            }
                            onRemove={() =>
                                onManualChange(
                                    manual.filter(
                                        (other) => other.id !== payment.id,
                                    ),
                                )
                            }
                        />
                    ))}
                    <span className="text-xs text-muted-foreground">
                        {__(
                            'If a big expense we did not expect comes in some month, we will ask you whether it repeats every year.',
                        )}
                    </span>
                    {!isPro && (
                        <span
                            className="text-xs text-muted-foreground"
                            data-test="forecast-review-free-note"
                        >
                            {__(
                                'With the free plan you see next month: the payments that fall later are kept and show in the months Pro unlocks.',
                            )}
                        </span>
                    )}
                </section>
            </div>
        </ReviewStepLayout>
    );
}

function AnnualSuggestionRow({
    suggestion,
    added,
    onToggle,
}: {
    suggestion: ForecastAnnualSuggestion;
    added: boolean;
    onToggle: () => void;
}) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const evidence = annualEvidence(suggestion, locale, (amount) =>
        formatAmount(amount, suggestion.currencyCode, { fractionDigits: 0 }),
    );

    return (
        <div
            className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-lg border border-warning/30 bg-warning/10 p-3 sm:grid-cols-[minmax(0,1fr)_auto_auto]"
            data-test="forecast-review-annual-suggestion"
        >
            <span className="flex min-w-0 flex-col gap-0.5">
                <span className="text-sm font-medium">{suggestion.name}</span>
                <span className="text-xs text-muted-foreground">
                    {[evidence, suggestion.accountName]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </span>
            <span className="text-sm font-medium tabular-nums">
                <AmountDisplay
                    amountInCents={Math.abs(suggestion.amount)}
                    currencyCode={suggestion.currencyCode}
                    maximumFractionDigits={0}
                    minimumFractionDigits={0}
                />
                {' · '}
                {formatMonthName(suggestion.nextDate.slice(0, 7), locale)}
            </span>
            {added ? (
                <Button
                    variant="ghost"
                    size="sm"
                    className="col-span-2 justify-self-end text-success sm:col-span-1"
                    onClick={onToggle}
                    aria-pressed
                >
                    <Check />
                    {__('Added')}
                </Button>
            ) : (
                <Button
                    size="sm"
                    className="col-span-2 justify-self-end sm:col-span-1"
                    onClick={onToggle}
                    data-test="forecast-review-add-annual"
                >
                    {__('Add yearly payment')}
                </Button>
            )}
        </div>
    );
}

function ManualPaymentRow({
    payment,
    accounts,
    onChange,
    onRemove,
}: {
    payment: ManualPaymentDraft;
    accounts: ForecastReview['accounts'];
    onChange: (change: Partial<ManualPaymentDraft>) => void;
    onRemove: () => void;
}) {
    const locale = useLocale();
    const preset = QUICK_ADDS.find(
        (quickAdd) => quickAdd.id === payment.presetId,
    );
    const account = accounts.find((option) => option.id === payment.accountId);
    const label = payment.name || __('Other payment');
    const amountId = `${payment.id}-amount`;

    return (
        <div
            className="grid grid-cols-2 items-center gap-2 rounded-lg border p-2 sm:grid-cols-[minmax(0,1.2fr)_110px_130px_minmax(0,1.2fr)_40px]"
            data-test="forecast-review-manual-row"
        >
            <span className="col-span-2 flex min-w-0 flex-col gap-0.5 pl-1 sm:col-span-1">
                {payment.presetId === 'other' ? (
                    <Input
                        value={payment.name}
                        placeholder={__('What is it?')}
                        aria-label={__('Name')}
                        maxLength={255}
                        className="h-9"
                        onChange={(event) =>
                            onChange({ name: event.target.value })
                        }
                    />
                ) : (
                    <span className="truncate text-sm font-medium">
                        {payment.name}
                    </span>
                )}
                {preset?.eitherWay ? (
                    <Select
                        value={payment.direction}
                        onValueChange={(value) =>
                            onChange({ direction: value as 'in' | 'out' })
                        }
                    >
                        <SelectTrigger
                            className="h-7 w-fit gap-1 border-0 px-0 text-[11px] text-muted-foreground shadow-none"
                            aria-label={__('Payment or refund of :name', {
                                name: label,
                            })}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="out">{__('Payment')}</SelectItem>
                            <SelectItem value="in">{__('Refund')}</SelectItem>
                        </SelectContent>
                    </Select>
                ) : (
                    <span className="text-[11px] text-muted-foreground">
                        {preset?.kind()}
                    </span>
                )}
            </span>
            <span>
                <Label htmlFor={amountId} className="sr-only">
                    {__('Amount of :name', { name: label })}
                </Label>
                <AmountInput
                    id={amountId}
                    value={payment.amount}
                    onChange={(amount) =>
                        onChange({ amount: Math.abs(amount) })
                    }
                    currencyCode={account?.currencyCode ?? 'EUR'}
                    placeholder={__('Amount')}
                    commitOnChange
                />
            </span>
            <Select
                value={String(payment.month)}
                onValueChange={(value) => onChange({ month: Number(value) })}
            >
                <SelectTrigger
                    className="w-full"
                    aria-label={__('Month of :name', { name: label })}
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {MONTHS.map((month) => (
                        <SelectItem key={month} value={String(month)}>
                            <span className="capitalize">
                                {formatMonthName(monthKey(month), locale)}
                            </span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Select
                value={payment.accountId}
                onValueChange={(value) => onChange({ accountId: value })}
            >
                <SelectTrigger
                    className="w-full min-w-0 [&>span]:truncate"
                    aria-label={__('Account of :name', { name: label })}
                >
                    <SelectValue placeholder={__('Account')} />
                </SelectTrigger>
                <SelectContent>
                    {accounts.map((option) => (
                        <SelectItem key={option.id} value={option.id}>
                            {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Button
                variant="ghost"
                size="icon"
                className="justify-self-end"
                onClick={onRemove}
                aria-label={__('Remove :name', { name: label })}
            >
                <X />
            </Button>
        </div>
    );
}
