import { CurrencyPicker } from '@/components/onboarding/currency-picker';
import { guessRange } from '@/components/onboarding/guess-range';
import { StepButton } from '@/components/onboarding/step-button';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { type CurrencyOption } from '@/types/account';
import {
    getCurrencySymbol,
    toMajorUnits,
    toMinorUnits,
} from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { useState } from 'react';

interface StepGuessProps {
    currencyCode: string;
    currencies: CurrencyOption[];
    /** Minor units, from a run being resumed or a step gone back to. */
    value?: number;
    onCurrencyChange: (currencyCode: string) => void;
    onContinue: (spendingGuess: number) => void;
}

/** Past this many characters a guess no longer fits a phone at full size. */
const LONG_AMOUNT_LENGTH = 7;

/**
 * The hinge of the whole flow: it costs one drag, and it opens a question only
 * the app can close. A later step answers it with what the user actually spent.
 */
export function StepGuess({
    currencyCode,
    currencies,
    value,
    onCurrencyChange,
    onContinue,
}: StepGuessProps) {
    const locale = useLocale();
    const range = guessRange(currencyCode);
    // The amount remembers the currency it was dragged in. A guess made in one
    // currency means nothing in another, so whenever the prop moves — a pick,
    // or the pick rolled back after a failed save — the slider starts over at
    // that currency's own starting point.
    const [guess, setGuess] = useState(() => ({
        currencyCode,
        amount:
            value === undefined
                ? range.initial
                : toMajorUnits(value, currencyCode),
    }));
    const amount =
        guess.currencyCode === currencyCode ? guess.amount : range.initial;
    const setAmount = (major: number) =>
        setGuess({ currencyCode, amount: major });

    const progress = ((amount - range.min) / (range.max - range.min)) * 100;
    const format = (major: number) =>
        new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(
            major,
        );
    const formattedAmount = format(amount);

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
                <div className="flex flex-col items-center gap-4 pt-6 pb-1">
                    <CurrencyPicker
                        value={currencyCode}
                        options={currencies}
                        onChange={onCurrencyChange}
                    />
                    <div className="flex items-baseline justify-center gap-1.5">
                        <span className="text-[34px] leading-none font-medium text-muted-foreground">
                            {getCurrencySymbol(currencyCode)}
                        </span>
                        <span
                            className={cn(
                                'leading-none font-semibold tracking-[-0.04em] tabular-nums',
                                // Guaraní and peso guesses run to eight digits.
                                formattedAmount.length > LONG_AMOUNT_LENGTH
                                    ? 'text-[44px] sm:text-[64px]'
                                    : 'text-[64px]',
                            )}
                        >
                            {formattedAmount}
                        </span>
                    </div>
                </div>

                <div className="flex flex-col gap-3">
                    {/* A native range input: it is already draggable, keyboard
                        operable and announced correctly, and the only thing it
                        cannot do on its own is paint the track behind the thumb. */}
                    <input
                        type="range"
                        min={range.min}
                        max={range.max}
                        step={range.step}
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
                            {format(range.min)}
                        </span>
                        <span>
                            {getCurrencySymbol(currencyCode)}
                            {format(range.max)}+
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
