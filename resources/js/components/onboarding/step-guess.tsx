import { StepButton } from '@/components/onboarding/step-button';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { useLocale } from '@/hooks/use-locale';
import {
    getCurrencySymbol,
    toMajorUnits,
    toMinorUnits,
} from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

interface StepGuessProps {
    currencyCode: string;
    /** Minor units, from a run being resumed or a step gone back to. */
    value?: number;
    onContinue: (spendingGuess: number) => void;
}

/**
 * The slider's range, in major units of whatever the user's currency is. It is
 * a guess, so the steps are coarse on purpose: the point is the gap between
 * this number and the real one, not the number.
 */
const MIN = 300;
const MAX = 5000;
const STEP = 50;
const DEFAULT = 1200;

/**
 * The hinge of the whole flow: it costs one drag, and it opens a question only
 * the app can close. A later step answers it with what the user actually spent.
 */
export function StepGuess({ currencyCode, value, onContinue }: StepGuessProps) {
    const locale = useLocale();
    const [amount, setAmount] = useState(
        value === undefined ? DEFAULT : toMajorUnits(value, currencyCode),
    );

    const progress = ((amount - MIN) / (MAX - MIN)) * 100;
    const format = (major: number) =>
        new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(
            major,
        );

    return (
        <StepScreen
            title={__('What did you spend last month?')}
            description={__(
                'Your best guess, before we look. Everything you spent, on everything.',
            )}
            footer={
                <>
                    <StepButton
                        text={__('Lock in my guess')}
                        onClick={() =>
                            onContinue(toMinorUnits(amount, currencyCode))
                        }
                    />
                    <StepNote>{__('Nobody sees this but you.')}</StepNote>
                </>
            }
        >
            <div className="flex flex-col gap-5.5">
                <div className="flex items-baseline justify-center gap-1.5 pt-6 pb-1">
                    <span className="text-[34px] leading-none font-medium text-muted-foreground">
                        {getCurrencySymbol(currencyCode)}
                    </span>
                    <span className="text-[64px] leading-none font-semibold tracking-[-0.04em] tabular-nums">
                        {format(amount)}
                    </span>
                </div>

                <div className="flex flex-col gap-3">
                    {/* A native range input: it is already draggable, keyboard
                        operable and announced correctly, and the only thing it
                        cannot do on its own is paint the track behind the thumb. */}
                    <input
                        type="range"
                        min={MIN}
                        max={MAX}
                        step={STEP}
                        value={amount}
                        onChange={(event) =>
                            setAmount(Number(event.target.value))
                        }
                        aria-label={__('What did you spend last month?')}
                        style={{
                            background: `linear-gradient(to right, var(--foreground) ${progress}%, var(--border) ${progress}%)`,
                        }}
                        className="h-1 w-full cursor-pointer appearance-none rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&::-moz-range-thumb]:size-6.5 [&::-moz-range-thumb]:cursor-pointer [&::-moz-range-thumb]:rounded-full [&::-moz-range-thumb]:border-[1.5px] [&::-moz-range-thumb]:border-foreground [&::-moz-range-thumb]:bg-background [&::-webkit-slider-thumb]:size-6.5 [&::-webkit-slider-thumb]:cursor-pointer [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:border-[1.5px] [&::-webkit-slider-thumb]:border-foreground [&::-webkit-slider-thumb]:bg-background [&::-webkit-slider-thumb]:shadow-sm"
                    />
                    <div className="flex justify-between text-[13px] text-muted-foreground">
                        <span>
                            {getCurrencySymbol(currencyCode)}
                            {format(MIN)}
                        </span>
                        <span>
                            {getCurrencySymbol(currencyCode)}
                            {format(MAX)}+
                        </span>
                    </div>
                </div>

                <div className="flex flex-col gap-1.5 rounded-lg bg-muted p-4 px-4.5">
                    <span className="text-sm leading-snug font-medium">
                        {__('Almost nobody gets this right.')}
                    </span>
                    <span className="text-sm leading-normal text-pretty text-muted-foreground">
                        {__(
                            "That gap — between what you think you spend and what you do — is the whole reason you're here. We'll show you yours in about three minutes.",
                        )}
                    </span>
                </div>
            </div>
        </StepScreen>
    );
}
