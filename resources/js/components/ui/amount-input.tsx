import * as React from 'react';

import { Input } from '@/components/ui/input';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';

interface AmountInputProps {
    value: number;
    onChange: (valueInCents: number) => void;
    currencyCode: string;
    disabled?: boolean;
    required?: boolean;
    placeholder?: string;
    id?: string;
    className?: string;
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

const formatCurrency = (value: number, locale: string): string => {
    const amount = value / 100;
    return new Intl.NumberFormat(locale, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
};

const parseInputValue = (input: string): number => {
    const isNegative = input.trim().startsWith('-');
    const cleaned = input.replace(/[^\d.,]/g, '');

    if (!cleaned) {
        return 0;
    }

    const lastComma = cleaned.lastIndexOf(',');
    const lastDot = cleaned.lastIndexOf('.');

    let normalized: string;

    if (lastComma > lastDot) {
        normalized = cleaned.replace(/\./g, '').replace(',', '.');
    } else if (lastDot > lastComma) {
        normalized = cleaned.replace(/,/g, '');
    } else {
        normalized = cleaned.replace(',', '.');
    }

    const parsed = parseFloat(normalized);

    if (isNaN(parsed)) {
        return 0;
    }

    const cents = Math.round(parsed * 100);
    return isNegative ? -cents : cents;
};

const evaluateMathExpression = (input: string): number | null => {
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

        // Helper to convert parsed cents to dollars for calculation
        const parseToDollars = (str: string): number => {
            return parseInputValue(str) / 100;
        };

        // Split into tokens (numbers and operators)
        const tokens: (number | string)[] = [];
        let currentNumber = '';

        for (let i = 0; i < cleaned.length; i++) {
            const char = cleaned[i];
            if (['+', '-', '*', '/'].includes(char) && currentNumber) {
                tokens.push(parseToDollars(currentNumber));
                tokens.push(char);
                currentNumber = '';
            } else {
                currentNumber += char;
            }
        }
        if (currentNumber) {
            tokens.push(parseToDollars(currentNumber));
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

        return Math.round(result * 100);
    } catch {
        return null;
    }
};

export const AmountInput = React.forwardRef<HTMLInputElement, AmountInputProps>(
    (
        {
            value,
            onChange,
            currencyCode,
            disabled = false,
            required = false,
            placeholder = '0.00',
            id,
            className = '',
        },
        ref,
    ) => {
        const locale = useLocale();
        const [displayValue, setDisplayValue] = React.useState<string>('');
        const [isFocused, setIsFocused] = React.useState<boolean>(false);

        React.useEffect(() => {
            if (!isFocused) {
                if (value === 0) {
                    setDisplayValue('');
                } else {
                    setDisplayValue(formatCurrency(value, locale));
                }
            }
        }, [value, isFocused, locale]);

        const handleFocus = () => {
            setIsFocused(true);
            if (value !== 0) {
                const amount = (value / 100).toFixed(2);
                setDisplayValue(amount);
            } else {
                setDisplayValue('');
            }
        };

        const handleBlur = () => {
            setIsFocused(false);

            // Try to evaluate as math expression first
            const mathResult = evaluateMathExpression(displayValue);
            const valueInCents = mathResult !== null ? mathResult : parseInputValue(displayValue);

            onChange(valueInCents);
        };

        const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
            setDisplayValue(e.target.value);
        };

        const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
            if (e.key === 'Enter') {
                // Try to evaluate as math expression first
                const mathResult = evaluateMathExpression(displayValue);
                const valueInCents = mathResult !== null ? mathResult : parseInputValue(displayValue);

                onChange(valueInCents);
            }
        };

        const { symbol: currencySymbol, position: symbolPosition } = getCurrencyInfo(currencyCode, locale);

        return (
            <div className="relative">
                {symbolPosition === 'prefix' && (
                    <span className="-translate-y-1/2 absolute top-1/2 left-3 text-muted-foreground text-sm">
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
                    placeholder={placeholder}
                    disabled={disabled}
                    required={required}
                    className={cn([
                        'bg-background',
                        symbolPosition === 'prefix' ? 'pl-9' : 'pr-9',
                        className,
                    ])}
                />
                {symbolPosition === 'suffix' && (
                    <span className="-translate-y-1/2 absolute top-1/2 right-3 text-muted-foreground text-sm">
                        {currencySymbol}
                    </span>
                )}
            </div>
        );
    },
);

AmountInput.displayName = 'AmountInput';

