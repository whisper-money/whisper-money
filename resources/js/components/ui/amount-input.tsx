import * as React from 'react';

import { Input } from '@/components/ui/input';
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

const getCurrencySymbol = (currencyCode: string): string => {
    const symbols: Record<string, string> = {
        USD: '$',
        EUR: '€',
        GBP: '£',
        JPY: '¥',
    };
    return symbols[currencyCode] || currencyCode;
};

const formatCurrency = (value: number): string => {
    const amount = value / 100;
    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
};

const parseInputValue = (input: string): number => {
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

    return Math.round(parsed * 100);
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
        const [displayValue, setDisplayValue] = React.useState<string>('');
        const [isFocused, setIsFocused] = React.useState<boolean>(false);

        React.useEffect(() => {
            if (!isFocused) {
                if (value === 0) {
                    setDisplayValue('');
                } else {
                    setDisplayValue(formatCurrency(value));
                }
            }
        }, [value, isFocused]);

        const handleFocus = () => {
            setIsFocused(true);
            if (value > 0) {
                const amount = (value / 100).toFixed(2);
                setDisplayValue(amount);
            } else {
                setDisplayValue('');
            }
        };

        const handleBlur = () => {
            setIsFocused(false);
            const valueInCents = parseInputValue(displayValue);
            onChange(valueInCents);
        };

        const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
            setDisplayValue(e.target.value);
        };

        const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
            if (e.key === 'Enter') {
                const valueInCents = parseInputValue(displayValue);
                onChange(valueInCents);
            }
        };

        const currencySymbol = getCurrencySymbol(currencyCode);

        return (
            <div className="relative">
                <span className="-translate-y-1/2 absolute top-1/2 left-3 text-muted-foreground text-sm">
                    {currencySymbol}
                </span>
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
                    className={cn(["bg-background pl-9", className])}
                />
            </div>
        );
    },
);

AmountInput.displayName = 'AmountInput';

