import {
    destroy,
    update,
} from '@/actions/App/Http/Controllers/Forecast/ForecastItemController';
import {
    type ForecastSheetProps,
    useForecastEditor,
} from '@/components/forecast/forecast-editor';
import {
    Field,
    ForecastSheet,
    FrequencySelect,
    ImpactCallout,
    ScopeChoice,
} from '@/components/forecast/forecast-sheet-parts';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useForecastSave } from '@/hooks/use-forecast-save';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { monthStart } from '@/lib/forecast/calendar';
import {
    accountMonths,
    amountOn,
    editedDefinitions,
    impactOn,
    type LineEdit,
    type LineEditScope,
    withLine,
} from '@/lib/forecast/edits';
import {
    dayOf,
    lineKindLabel,
    monthLongName,
    monthWithYear,
} from '@/lib/forecast/format';
import { type ForecastFrequency } from '@/lib/forecast/types';
import { type ForecastLine } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

/** The signed amount the line's occurrence on `date` has today. */
function occurrenceAmount(line: ForecastLine, date: string): number {
    const override = line.definition.overrides?.find(
        (candidate) => candidate.occurrenceDate === date,
    );

    return override?.amount ?? amountOn(line.definition.versions, date) ?? 0;
}

function PaymentsList({
    line,
    currencyCode,
}: {
    line: ForecastLine;
    currencyCode: string;
}) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const amount = Math.abs(amountOn(line.definition.versions, '9999') ?? 0);
    const percent =
        amount === 0 ? 0 : Math.round((100 * line.amountTolerance) / amount);
    const values = {
        name: line.matchText ?? line.name,
        days: line.dateWindowDays,
        percent,
    };

    return (
        <div className="flex flex-col gap-2 rounded-lg bg-muted/60 p-3.5">
            <span className="text-sm font-medium">{__('Planned vs real')}</span>
            {line.payments.length > 0 ? (
                <div className="grid grid-cols-[minmax(0,1fr)_auto_auto] gap-x-4 gap-y-1.5 text-sm tabular-nums">
                    {line.payments.map((payment) => (
                        <div key={payment.occurrenceDate} className="contents">
                            <span className="text-muted-foreground">
                                {monthLongName(
                                    payment.occurrenceDate.slice(0, 7),
                                    locale,
                                )}
                            </span>
                            <span>
                                {formatAmount(
                                    Math.abs(payment.real),
                                    currencyCode,
                                    { fractionDigits: 0 },
                                )}
                            </span>
                            <span className="text-success">
                                {__('✓ matched')}
                            </span>
                        </div>
                    ))}
                </div>
            ) : (
                <span className="text-sm text-muted-foreground">
                    {__('No payment has been matched to it yet.')}
                </span>
            )}
            <span className="text-xs text-muted-foreground">
                {line.matchText
                    ? __(
                          'We only match it with payments of «:name» within ±:days days and ±:percent% of the amount.',
                          values,
                      )
                    : __(
                          'We match it with payments within ±:days days and ±:percent% of the amount.',
                          values,
                      )}
            </span>
        </div>
    );
}

function DriftOffer({
    line,
    currencyCode,
    onAccept,
}: {
    line: ForecastLine;
    currencyCode: string;
    onAccept: () => void;
}) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();

    if (line.drift === null) {
        return null;
    }

    return (
        <div
            className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-warning/10 p-3 text-sm"
            data-test="forecast-drift"
        >
            <span>
                {__('Your :name is now :amount. Update from :month?', {
                    name: line.name,
                    amount: formatAmount(
                        Math.abs(line.drift.amount),
                        currencyCode,
                        { fractionDigits: 0 },
                    ),
                    month: monthLongName(line.drift.from.slice(0, 7), locale),
                })}
            </span>
            <Button size="sm" variant="outline" onClick={onAccept}>
                {__('Update')}
            </Button>
        </div>
    );
}

/**
 * "Editar previsión": one occurrence of a concrete line. The amount, the
 * months it affects (only this one, or from it on), the repetition and the
 * day; what was paid before and how it is recognised; what the change does
 * to the lowest balance, worked out on the client before saving.
 */
export function ForecastLineSheet({
    forecast,
    projectionInput,
    onClose,
    line,
    month,
}: ForecastSheetProps & { line: ForecastLine; month: string }) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const { save, processing, errors } = useForecastSave();
    const editor = useForecastEditor();
    const occurrenceDate =
        line.occurrenceDates[month] ?? line.current.date ?? `${month}-01`;
    const original = occurrenceAmount(line, occurrenceDate);
    const sign = original < 0 ? -1 : 1;
    const isOnce = line.definition.frequency === 'once';
    const [amount, setAmount] = useState(Math.abs(original));
    const [scope, setScope] = useState<LineEditScope>('from_here');
    const [frequency, setFrequency] = useState<ForecastFrequency>(
        line.definition.frequency,
    );
    const [day, setDay] = useState(String(dayOf(occurrenceDate)));
    const currencyCode = forecast.currencyCode;
    const format = (value: number) =>
        formatAmount(Math.abs(value), currencyCode, { fractionDigits: 0 });
    const monthLabel = monthWithYear(month, locale);

    const parsedDay = Number(day);
    const edit: LineEdit = {
        scope,
        occurrenceDate,
        amount: amount === Math.abs(original) ? null : sign * amount,
        frequency:
            scope === 'from_here' && frequency !== line.definition.frequency
                ? frequency
                : null,
        day:
            Number.isInteger(parsedDay) &&
            parsedDay >= 1 &&
            parsedDay <= 31 &&
            parsedDay !== dayOf(occurrenceDate)
                ? parsedDay
                : null,
    };
    const edited = withLine(
        projectionInput,
        line.id,
        editedDefinitions(line.definition, edit, forecast.today),
    );
    const impact = impactOn(
        forecast.projection,
        accountMonths(edited, forecast.account.id),
    );
    const unchanged =
        edit.amount === null && edit.frequency === null && edit.day === null;

    const submit = () =>
        save(
            update(line.id),
            {
                scope: edit.scope,
                occurrence_date: occurrenceDate,
                amount: edit.amount === null ? null : amount,
                frequency: edit.frequency,
                day: edit.day,
            },
            { input: edited, itemIds: [line.id] },
            onClose,
        );

    const stop = () =>
        save(
            destroy(line.id),
            {},
            {
                input: withLine(projectionInput, line.id, []),
                itemIds: [line.id],
            },
            onClose,
        );

    return (
        <ForecastSheet
            open
            onOpenChange={(open) => !open && onClose()}
            eyebrow={`${lineKindLabel(line.kind)} · ${forecast.account.name}`}
            title={`${line.name} · ${monthLabel}`}
            testId="forecast-line-sheet"
            footer={
                <>
                    <Button
                        variant="ghost"
                        className="text-destructive hover:text-destructive"
                        onClick={stop}
                        disabled={processing}
                    >
                        {__('Stop forecasting it')}
                    </Button>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onClose}>
                            {__('Cancel')}
                        </Button>
                        <Button
                            onClick={submit}
                            disabled={processing || unchanged}
                        >
                            {__('Save')}
                        </Button>
                    </div>
                </>
            }
        >
            <Field
                label={__('Amount')}
                htmlFor="forecast-line-amount"
                hint={__('Before: :amount', { amount: format(original) })}
                error={errors.amount}
            >
                <AmountInput
                    id="forecast-line-amount"
                    value={amount}
                    onChange={(value) => setAmount(Math.abs(value))}
                    currencyCode={currencyCode}
                    commitOnChange
                />
            </Field>

            {!isOnce && (
                <ScopeChoice
                    value={scope}
                    onChange={setScope}
                    options={[
                        {
                            value: 'occurrence',
                            label: __('Only :month', { month: monthLabel }),
                            description: __(
                                'An exception. The other months stay at :amount.',
                                { amount: format(original) },
                            ),
                        },
                        {
                            value: 'from_here',
                            label: __(':month onwards', {
                                month: monthLabel,
                            }),
                            description: __(
                                'Until :month it stays at :amount.',
                                {
                                    month: monthLongName(
                                        monthStart(occurrenceDate, -1).slice(
                                            0,
                                            7,
                                        ),
                                        locale,
                                    ),
                                    amount: format(original),
                                },
                            ),
                        },
                    ]}
                />
            )}
            {errors.occurrence_date && (
                <p className="text-sm text-destructive">
                    {errors.occurrence_date}
                </p>
            )}

            <div className="grid grid-cols-2 gap-3">
                <Field
                    label={__('Repetition')}
                    htmlFor="forecast-line-frequency"
                >
                    <FrequencySelect
                        id="forecast-line-frequency"
                        value={frequency}
                        onChange={setFrequency}
                        disabled={scope === 'occurrence'}
                    />
                </Field>
                <Field
                    label={__('Day')}
                    htmlFor="forecast-line-day"
                    error={errors.day}
                >
                    <Input
                        id="forecast-line-day"
                        inputMode="numeric"
                        value={day}
                        onChange={(event) => setDay(event.target.value)}
                    />
                </Field>
            </div>

            <DriftOffer
                line={line}
                currencyCode={currencyCode}
                onAccept={() => {
                    editor?.acceptDrift(line);
                    onClose();
                }}
            />

            <PaymentsList line={line} currencyCode={currencyCode} />

            <ImpactCallout
                impact={impact}
                currencyCode={currencyCode}
                threshold={forecast.account.alertThreshold}
            />
        </ForecastSheet>
    );
}
