import { store } from '@/actions/App/Http/Controllers/Forecast/ForecastEstimateController';
import { type ForecastSheetProps } from '@/components/forecast/forecast-editor';
import {
    Field,
    ForecastSheet,
    ImpactCallout,
    Note,
    ScopeChoice,
} from '@/components/forecast/forecast-sheet-parts';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { useForecastSave } from '@/hooks/use-forecast-save';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import {
    accountMonths,
    estimateDeltas,
    estimateKind,
    type EstimateScope,
    impactOn,
    withEstimateAmounts,
    withEstimateDeltas,
} from '@/lib/forecast/edits';
import { monthWithYear } from '@/lib/forecast/format';
import { estimateBand, groupName } from '@/lib/forecast/plan-rows';
import { type ForecastGroup } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

/** What the group's estimate plans for the month now, positive. */
function plannedIn(group: ForecastGroup, month: string, current: boolean) {
    if (current) {
        return group.estimate?.current?.planned ?? 0;
    }

    return Math.abs(group.estimate?.amounts[month] ?? 0);
}

/**
 * Adjusts the estimate of a category on the account (income or spend,
 * "Other income" and "Other spending" included), for one month or from it
 * on. Only the forecast changes; the budget stays as it is.
 */
export function ForecastEstimateSheet({
    forecast,
    projectionInput,
    onClose,
    group,
    month,
}: ForecastSheetProps & { group: ForecastGroup; month: string }) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const { save, processing, errors } = useForecastSave();
    const visible = forecast.months
        .filter((column) => column.status !== 'locked')
        .map((column) => column.month);
    const original = plannedIn(group, month, month === visible[0]);
    const [amount, setAmount] = useState(original);
    const [scope, setScope] = useState<EstimateScope>('from_here');
    const monthLabel = monthWithYear(month, locale);
    const name = groupName(group);
    const currencyCode = forecast.currencyCode;
    const kind = estimateKind(group);
    const format = (value: number) =>
        formatAmount(value, currencyCode, { fractionDigits: 0 });

    const deltas = estimateDeltas(group, amount, month, scope, visible);
    const edited = withEstimateDeltas(
        projectionInput,
        {
            accountId: forecast.account.id,
            categoryId: group.categoryId,
            kind,
        },
        deltas,
    );
    const impact = impactOn(
        forecast.projection,
        accountMonths(edited, forecast.account.id),
    );

    const submit = () =>
        save(
            store(),
            {
                account_id: forecast.account.id,
                category_id: group.categoryId,
                kind,
                month,
                amount,
                scope,
            },
            {
                input: edited,
                forecast: withEstimateAmounts(forecast, group, deltas),
            },
            onClose,
        );

    return (
        <ForecastSheet
            open
            onOpenChange={(open) => !open && onClose()}
            eyebrow={`${kind === 'income' ? __('Income') : __('Expenses')} · ${forecast.account.name}`}
            title={`${name} · ${monthLabel}`}
            testId="forecast-estimate-sheet"
            footer={
                <div className="ml-auto flex gap-2">
                    <Button variant="outline" onClick={onClose}>
                        {__('Cancel')}
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={processing || amount === original}
                    >
                        {__('Save')}
                    </Button>
                </div>
            }
        >
            <Field
                label={
                    kind === 'income'
                        ? __('What you expect to receive')
                        : __('What you expect to spend')
                }
                htmlFor="forecast-estimate-amount"
                hint={__('Now: :amount, likely ± :band', {
                    amount: format(original),
                    band: format(estimateBand(kind, original)),
                })}
                error={errors.amount}
            >
                <AmountInput
                    id="forecast-estimate-amount"
                    value={amount}
                    onChange={(value) => setAmount(Math.abs(value))}
                    currencyCode={currencyCode}
                    commitOnChange
                />
            </Field>

            <ScopeChoice
                value={scope}
                onChange={setScope}
                options={[
                    {
                        value: 'month',
                        label: __('Only :month', { month: monthLabel }),
                        description: __(
                            'A one-time adjustment. The other months keep their estimate.',
                        ),
                    },
                    {
                        value: 'from_here',
                        label: __(':month onwards', { month: monthLabel }),
                        description: __(
                            'Replaces the estimate from this month on.',
                        ),
                    },
                ]}
            />
            {(errors.month || errors.kind) && (
                <p className="text-sm text-destructive">
                    {errors.month ?? errors.kind}
                </p>
            )}

            {group.items.length > 0 && (
                <Note>
                    {__(
                        'The yearly and one-off payments you added to :group come on top of this figure.',
                        { group: name },
                    )}
                </Note>
            )}

            <Note>
                {__(
                    'This only changes the forecast: your budget stays as it is.',
                )}
            </Note>

            <ImpactCallout
                impact={impact}
                currencyCode={currencyCode}
                threshold={forecast.account.alertThreshold}
                today={forecast.today}
            />
        </ForecastSheet>
    );
}
