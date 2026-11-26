import { update } from '@/actions/App/Http/Controllers/Forecast/ForecastAccountSettingController';
import { type ForecastSheetProps } from '@/components/forecast/forecast-editor';
import {
    Field,
    ForecastSheet,
} from '@/components/forecast/forecast-sheet-parts';
import { AmountInput } from '@/components/ui/amount-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForecastSave } from '@/hooks/use-forecast-save';
import { alertThreshold, withAccountSettings } from '@/lib/forecast/edits';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

/** Accounts whose balance grows on its own: a rate makes sense for them. */
const GROWING = ['investment', 'retirement', 'real_estate', 'savings'];

/**
 * How the account takes part in the forecast: the balance its owner wants
 * to keep (the warnings read against it), its yearly growth, and whether
 * the forecast covers it at all.
 */
export function ForecastAccountSheet({
    forecast,
    projectionInput,
    onClose,
}: ForecastSheetProps) {
    const { save, processing, errors } = useForecastSave();
    const account = forecast.account;
    const [minimum, setMinimum] = useState<number | null>(
        account.minimumBalance,
    );
    const [rate, setRate] = useState(
        account.annualRateBps === null
            ? ''
            : String(account.annualRateBps / 100),
    );
    const [included, setIncluded] = useState(true);
    const rateBps = rate.trim() === '' ? null : Math.round(Number(rate) * 100);

    const submit = () => {
        const threshold = alertThreshold(account.type, minimum);

        save(
            update(account.id),
            {
                minimum_balance: minimum,
                annual_rate: rate.trim() === '' ? null : Number(rate),
                included,
            },
            included
                ? {
                      input: withAccountSettings(projectionInput, account.id, {
                          minimumBalance: minimum,
                          annualRateBps: Number.isNaN(rateBps) ? null : rateBps,
                      }),
                      forecast: {
                          ...forecast,
                          account: {
                              ...account,
                              minimumBalance: minimum,
                              alertThreshold: threshold,
                          },
                      },
                  }
                : null,
            onClose,
        );
    };

    return (
        <ForecastSheet
            open
            onOpenChange={(open) => !open && onClose()}
            eyebrow={__('Forecast settings')}
            title={account.name}
            testId="forecast-account-sheet"
            footer={
                <div className="ml-auto flex gap-2">
                    <Button variant="outline" onClick={onClose}>
                        {__('Cancel')}
                    </Button>
                    <Button onClick={submit} disabled={processing}>
                        {__('Save')}
                    </Button>
                </div>
            }
        >
            <Field
                label={__('Wanted minimum')}
                htmlFor="forecast-account-minimum"
                hint={__(
                    'We warn you when the forecast drops below it. Leave it empty to be warned below zero.',
                )}
                error={errors.minimum_balance}
            >
                <AmountInput
                    id="forecast-account-minimum"
                    value={minimum ?? 0}
                    onChange={(value, isEmpty) =>
                        setMinimum(isEmpty ? null : value)
                    }
                    currencyCode={account.currencyCode}
                    placeholder={__('No minimum')}
                    commitOnChange
                />
            </Field>

            {GROWING.includes(account.type) && (
                <Field
                    label={__('Yearly growth (%)')}
                    htmlFor="forecast-account-rate"
                    hint={__(
                        'How much the balance grows each year on its own, such as an index fund.',
                    )}
                    error={errors.annual_rate}
                >
                    <Input
                        id="forecast-account-rate"
                        type="number"
                        step="0.1"
                        inputMode="decimal"
                        value={rate}
                        onChange={(event) => setRate(event.target.value)}
                    />
                </Field>
            )}

            <div className="flex items-start gap-3">
                <Checkbox
                    id="forecast-account-included"
                    checked={included}
                    onCheckedChange={(checked) => setIncluded(checked === true)}
                />
                <div className="grid gap-1">
                    <Label htmlFor="forecast-account-included">
                        {__('Include this account in the forecast')}
                    </Label>
                    <span className="text-xs text-muted-foreground">
                        {__(
                            'Left out, it gets no forecast and its lines stop counting.',
                        )}
                    </span>
                </div>
            </div>
        </ForecastSheet>
    );
}
