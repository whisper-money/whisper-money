import { StepButton } from '@/components/onboarding/step-button';
import {
    StepList,
    StepNumber,
    StepRow,
} from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { useLocale } from '@/hooks/use-locale';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { type ReactNode } from 'react';

interface StepPlanProps {
    /** The chosen goal, already written the way it reads mid-sentence. */
    goal?: string;
    /** Minor units. */
    spendingGuess?: number;
    currencyCode: string;
    onContinue: () => void;
}

/**
 * The four questions, read back before anything is asked of the user.
 *
 * Its answers come off the wizard's state, so a run resumed on this step with
 * nothing answered — a deep link, mostly — falls back to the plain version
 * rather than telling the user they want to "undefined".
 */
export function StepPlan({
    goal,
    spendingGuess,
    currencyCode,
    onContinue,
}: StepPlanProps) {
    const locale = useLocale();

    return (
        <StepScreen
            title={__("Here's what happens next")}
            description={
                goal !== undefined && spendingGuess !== undefined
                    ? readBack({
                          goal,
                          amount: formatCurrency(
                              spendingGuess,
                              currencyCode,
                              locale,
                              0,
                              0,
                          ),
                      })
                    : __('Three steps, and the real number at the end of them.')
            }
            footer={
                <>
                    <StepButton text={__("Let's go")} onClick={onContinue} />
                    <StepNote>{__('About three minutes from here.')}</StepNote>
                </>
            }
        >
            <StepList>
                <StepRow
                    leading={<StepNumber>1</StepNumber>}
                    title={__('A year of your spending, without typing it')}
                    description={__(
                        "Forty seconds at your bank's own login, read-only.",
                    )}
                />
                <StepRow
                    leading={<StepNumber>2</StepNumber>}
                    title={__('Every movement already filed when you arrive')}
                    description={__(
                        'You correct the handful we get wrong, once.',
                    )}
                />
                <StepRow
                    leading={<StepNumber>3</StepNumber>}
                    title={__('The real number, and what to do about it')}
                    description={__(
                        'Your first target comes out of it, not out of a guess.',
                    )}
                />
            </StepList>
        </StepScreen>
    );
}

/**
 * The user's own two answers, set in the running sentence that carries them.
 *
 * Split before the placeholders are filled in rather than after, so the whole
 * sentence stays one translatable string and the emphasis still lands on the
 * two words that are the user's rather than ours.
 */
function readBack(values: Record<string, string>): ReactNode {
    return __(
        "You want to :goal, and you think that's about :amount a month. Three steps to find out.",
    )
        .split(/(:goal|:amount)/)
        .map((part, index) =>
            part.startsWith(':') && part.slice(1) in values ? (
                <span key={index} className="font-medium text-foreground">
                    {values[part.slice(1)]}
                </span>
            ) : (
                part
            ),
        );
}
