import { update } from '@/actions/App/Http/Controllers/Forecast/ForecastAccountSettingController';
import { type ForecastSheetProps } from '@/components/forecast/forecast-editor';
import { ForecastSheet } from '@/components/forecast/forecast-sheet-parts';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { useForecastSave } from '@/hooks/use-forecast-save';
import { type ForecastAccountChoice } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { useId } from 'react';

/** What being in the forecast, or out of it, means for one account. */
function choiceDescription(choice: ForecastAccountChoice): string {
    if (!choice.included) {
        return __('Not in the forecast');
    }

    return choice.projectionOnly
        ? __('Projection only: counts toward the total, never warns')
        : __('In the forecast');
}

/**
 * Every account of the space, ticked when the forecast covers it. By
 * default a loan or a property is left out and an investment is projected
 * only; ticking or unticking one saves it at once.
 */
export function ForecastAccountChoices({
    choices,
}: {
    choices: ForecastAccountChoice[];
}) {
    const id = useId();
    const { save, processing } = useForecastSave();
    const toggle = (choice: ForecastAccountChoice, included: boolean) =>
        save(update(choice.id), { included }, null, undefined, {
            toastErrors: true,
        });

    return (
        <fieldset
            className="flex flex-col gap-3"
            data-test="forecast-account-choices"
        >
            <legend className="mb-1 text-sm font-medium">
                {__('Accounts in the forecast')}
            </legend>
            {choices.map((choice) => (
                <div
                    key={choice.id}
                    className="flex items-start gap-3"
                    data-test={`forecast-account-choice-${choice.id}`}
                >
                    <Checkbox
                        id={`${id}-${choice.id}`}
                        checked={choice.included}
                        disabled={processing}
                        onCheckedChange={(checked) =>
                            toggle(choice, checked === true)
                        }
                        className="mt-0.5"
                    />
                    <Label
                        htmlFor={`${id}-${choice.id}`}
                        className="flex flex-col items-start gap-0.5 font-normal"
                    >
                        <span className="text-sm font-medium">
                            {choice.name}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {choiceDescription(choice)}
                        </span>
                    </Label>
                </div>
            ))}
        </fieldset>
    );
}

/** The accounts the forecast covers, out of any one account's settings. */
export function ForecastAccountsSheet({
    forecast,
    onClose,
}: ForecastSheetProps) {
    return (
        <ForecastSheet
            open
            onOpenChange={(open) => !open && onClose()}
            eyebrow={__('Forecast settings')}
            title={__('Accounts in the forecast')}
            testId="forecast-accounts-sheet"
            footer={
                <Button className="ml-auto" onClick={onClose}>
                    {__('Done')}
                </Button>
            }
        >
            <ForecastAccountChoices choices={forecast.accountChoices} />
        </ForecastSheet>
    );
}
