import * as React from 'react';

import { Input } from '@/components/ui/input';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import {
    currencyDecimals,
    toMajorUnits,
    toMinorUnits,
} from '@/utils/currency';
import { __ } from '@/utils/i18n';

interface AmountInputProps {
    value: number;
    /**
     * `isEmpty` tells apart "the user cleared the field" from "the user typed a
     * zero": both resolve to 0 cents, and some call sites need the difference.
     */
    onChange: (valueInCents: number, isEmpty: boolean) => void;
    currencyCode: string;
    disabled?: boolean;
    required?: boolean;
    /** Defaults to a zero at the currency's own precision. */
    placeholder?: string;
    id?: string;
    className?: string;
    allowNegative?: boolean;
    /**
     * Report every keystroke instead of waiting for blur. For inputs whose
     * surroundings react as you type — a running total, a submit button that
     * unlocks when the numbers add up — where waiting for blur reads as broken.
     */
    commitOnChange?: boolean;
}

const getCurrencyInfo = (
    currencyCode: string,
    locale: string,
): { symbol: string; position: 'prefix' | 'suffix' } => {
    const parts = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
    }).formatToParts(1);

    const symbolPart = parts.find((p) => p.type === 'currency');
    const symbol = symbolPart?.value ?? currencyCode;
    const symbolIndex = parts.findIndex((p) => p.type === 'currency');
    const literalIndex = parts.findIndex((p) => p.type === 'integer');
    const position = symbolIndex < literalIndex ? 'prefix' : 'suffix';

    return { symbol, position };
};

const formatAmount = (
    value: number,
    locale: string,
    currencyCode: string,
): string => {
    const decimals = currencyDecimals(currencyCode);

    return new Intl.NumberFormat(locale, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(toMajorUnits(value, currencyCode));
};

const occurrences = (text: string, character: string): number =>
    text.split(character).length - 1;

/**
 * Turn typed digits and separators into something `parseFloat` reads, without
 * knowing the user's locale: whichever of `.` or `,` comes last is the decimal
 * separator, and a repeated one can only be grouping.
 */
const normalizeAmountText = (cleaned: string, decimals: number): string => {
    // A currency with no minor unit cannot carry a decimal separator at all, so
    // every dot and comma in it groups thousands: "1.234.567" is 1234567 pesos.
    if (decimals === 0) {
        return cleaned.replace(/[.,]/g, '');
    }

    const lastComma = cleaned.lastIndexOf(',');
    const lastDot = cleaned.lastIndexOf('.');

    if (lastComma > lastDot) {
        return cleaned.replace(/\./g, '').replace(',', '.');
    }

    if (lastDot > lastComma) {
        // More than one dot rules out a decimal point: "1.234.567", not "1.234".
        return occurrences(cleaned, '.') > 1
            ? cleaned.replace(/\./g, '')
            : cleaned.replace(/,/g, '');
    }

    return cleaned.replace(',', '.');
};

const parseInputValue = (input: string, currencyCode: string): number => {
    const isNegative = input.trim().startsWith('-');
    const cleaned = input.replace(/[^\d.,]/g, '');

    if (!cleaned) {
        return 0;
    }

    const parsed = parseFloat(
        normalizeAmountText(cleaned, currencyDecimals(currencyCode)),
    );

    if (isNaN(parsed)) {
        return 0;
    }

    const minorUnits = toMinorUnits(parsed, currencyCode);

    return isNegative ? -minorUnits : minorUnits;
};

const evaluateMathExpression = (
    input: string,
    currencyCode: string,
): number | null => {
    // Check for leading minus (negative result)
    const trimmed = input.trim();
    const isNegativeResult = trimmed.startsWith('-');
    const withoutLeadingMinus = isNegativeResult ? trimmed.substring(1) : trimmed;

    // Check if input contains math operators (excluding leading minus)
    if (!/[+\-*/]/.test(withoutLeadingMinus)) {
        return null; // No math operation found
    }

    try {
        // Remove spaces
        const cleaned = withoutLeadingMinus.replace(/\s/g, '');

        // Operands are compared and combined in major units, so 12,50 + 3
        // means 15,50 rather than 12,53.
        const parseToMajorUnits = (str: string): number => {
            return toMajorUnits(parseInputValue(str, currencyCode), currencyCode);
        };

        // Split into tokens (numbers and operators)
        const tokens: (number | string)[] = [];
        let currentNumber = '';

        for (let i = 0; i < cleaned.length; i++) {
            const char = cleaned[i];
            if (['+', '-', '*', '/'].includes(char) && currentNumber) {
                tokens.push(parseToMajorUnits(currentNumber));
                tokens.push(char);
                currentNumber = '';
            } else {
                currentNumber += char;
            }
        }
        if (currentNumber) {
            tokens.push(parseToMajorUnits(currentNumber));
        }

        if (tokens.length < 3) {
            return null; // Need at least: number operator number
        }

        // Handle multiplication and division first (operator precedence)
        let i = 1;
        while (i < tokens.length) {
            if (tokens[i] === '*' || tokens[i] === '/') {
                const left = tokens[i - 1] as number;
                const op = tokens[i] as string;
                const right = tokens[i + 1] as number;

                const result = op === '*' ? left * right : left / right;
                tokens.splice(i - 1, 3, result);
            } else {
                i += 2;
            }
        }

        // Handle addition and subtraction
        let result = tokens[0] as number;
        for (let i = 1; i < tokens.length; i += 2) {
            const op = tokens[i] as string;
            const right = tokens[i + 1] as number;

            if (op === '+') {
                result += right;
            } else if (op === '-') {
                result -= right;
            }
        }

        // Apply negative sign if the input started with minus
        if (isNegativeResult) {
            result = -result;
        }

        return toMinorUnits(result, currencyCode);
    } catch {
        return null;
    }
};

/** Breathing room between the symbol and the number it labels. */
const SYMBOL_GAP = '0.75rem';

const resolveMinorUnits = (input: string, currencyCode: string): number =>
    evaluateMathExpression(input, currencyCode) ??
    parseInputValue(input, currencyCode);

export const AmountInput = React.forwardRef<HTMLInputElement, AmountInputProps>(
    (
        {
            value,
            onChange,
            currencyCode,
            disabled = false,
            required = false,
            placeholder,
            id,
            className = '',
            allowNegative = false,
            commitOnChange = false,
        },
        ref,
    ) => {
        const locale = useLocale();
        const zeroPlaceholder = (0).toFixed(currencyDecimals(currencyCode));
        const [displayValue, setDisplayValue] = React.useState<string>('');
        const [isFocused, setIsFocused] = React.useState<boolean>(false);

        React.useEffect(() => {
            if (!isFocused) {
                if (value === 0) {
                    setDisplayValue('');
                } else {
                    setDisplayValue(formatAmount(value, locale, currencyCode));
                }
            }
        }, [value, isFocused, locale, currencyCode]);

        const handleFocus = () => {
            setIsFocused(true);
            if (value !== 0) {
                const amount = toMajorUnits(value, currencyCode).toFixed(
                    currencyDecimals(currencyCode),
                );
                setDisplayValue(amount);
            } else if (!displayValue.startsWith('-')) {
                // Keep a lone '-' the user set via the toggle before typing.
                setDisplayValue('');
            }
        };

        const commit = (text: string) => {
            onChange(resolveMinorUnits(text, currencyCode), text.trim() === '');
        };

        const handleBlur = () => {
            setIsFocused(false);
            commit(displayValue);
        };

        const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
            setDisplayValue(e.target.value);

            if (commitOnChange) {
                commit(e.target.value);
            }
        };

        const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
            if (e.key === 'Enter') {
                commit(displayValue);
            }
        };

        // iOS numeric keypads (inputMode="decimal") have no minus key, so
        // negative amounts need an explicit toggle. onClick handles both pointer
        // and keyboard; onPointerDown preventDefault keeps the input focused,
        // avoiding the blur/reformat race with the effect above.
        const toggleSign = () => {
            const next = displayValue.trim().startsWith('-')
                ? displayValue.replace('-', '')
                : `-${displayValue}`;
            setDisplayValue(next);
            commit(next);
        };

        const isNegative = displayValue.trim().startsWith('-');

        const { symbol: currencySymbol, position: symbolPosition } = getCurrencyInfo(currencyCode, locale);

        // The symbol sits `symbolInset` in from the edge, so the input has to
        // reserve that plus the symbol's own width. Deriving the width from the
        // character count keeps a code like `BTC` or `CHF` off the number, which
        // a padding hardcoded for a one-character `€` did not.
        const symbolInset =
            symbolPosition === 'prefix' && allowNegative ? '2.5rem' : '0.75rem';
        const symbolRoom = `calc(${symbolInset} + ${currencySymbol.length}ch + ${SYMBOL_GAP})`;

        return (
            <div className="relative">
                {symbolPosition === 'prefix' && (
                    <span
                        className="-translate-y-1/2 absolute top-1/2 text-muted-foreground text-sm"
                        style={{ left: symbolInset }}
                    >
                        {currencySymbol}
                    </span>
                )}
                <Input
                    ref={ref}
                    id={id}
                    type="text"
                    inputMode="decimal"
                    value={displayValue}
                    onChange={handleChange}
                    onFocus={handleFocus}
                    onBlur={handleBlur}
                    onKeyDown={handleKeyDown}
                    placeholder={placeholder ?? zeroPlaceholder}
                    disabled={disabled}
                    required={required}
                    className={cn([
                        'bg-background',
                        allowNegative && symbolPosition === 'suffix' && 'pl-11',
                        className,
                    ])}
                    style={
                        symbolPosition === 'prefix'
                            ? { paddingLeft: symbolRoom }
                            : { paddingRight: symbolRoom }
                    }
                />
                {symbolPosition === 'suffix' && (
                    <span
                        className="-translate-y-1/2 absolute top-1/2 text-muted-foreground text-sm"
                        style={{ right: symbolInset }}
                    >
                        {currencySymbol}
                    </span>
                )}
                {allowNegative && (
                    <button
                        type="button"
                        onPointerDown={(e) => e.preventDefault()}
                        onClick={toggleSign}
                        disabled={disabled}
                        aria-label={isNegative ? __('Make amount positive') : __('Make amount negative')}
                        aria-pressed={isNegative}
                        className={cn([
                            '-translate-y-1/2 absolute top-1/2 left-[0.35rem] flex h-7 w-7 items-center justify-center rounded-sm border font-semibold text-lg leading-none transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
                            isNegative
                                ? 'border-destructive/40 bg-destructive/10 text-destructive'
                                : 'border-input bg-muted text-foreground hover:bg-accent',
                        ])}
                    >
                        {isNegative ? '−' : '+'}
                    </button>
                )}
            </div>
        );
    },
);

AmountInput.displayName = 'AmountInput';

