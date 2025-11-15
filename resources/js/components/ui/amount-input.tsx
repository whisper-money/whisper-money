import * as React from 'react';

import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';

interface AmountInputProps {
    value: number;
    onChange: (valueInCents: number) => void;
    currencyCode: string;
    disabled?: boolean;
    required?: boolean;
    placeholder?: string;
    id?: string;
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
        },
        ref,
    ) => {
        const [displayValue, setDisplayValue] = React.useState<string>('');

        React.useEffect(() => {
            if (value === 0) {
                setDisplayValue('');
            } else {
                setDisplayValue((value / 100).toFixed(2));
            }
        }, [value]);

        const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
            const inputValue = e.target.value;

            if (inputValue === '' || inputValue === '-') {
                setDisplayValue(inputValue);
                onChange(0);
                return;
            }

            const numericValue = inputValue.replace(/[^\d.-]/g, '');

            if (numericValue === '' || numericValue === '-') {
                setDisplayValue(numericValue);
                onChange(0);
                return;
            }

            const parsedValue = parseFloat(numericValue);

            if (isNaN(parsedValue)) {
                return;
            }

            setDisplayValue(numericValue);

            const valueInCents = Math.round(parsedValue * 100);
            onChange(valueInCents);
        };

        const handleBlur = () => {
            if (displayValue === '' || displayValue === '-') {
                setDisplayValue('');
                onChange(0);
                return;
            }

            const parsedValue = parseFloat(displayValue);
            if (!isNaN(parsedValue)) {
                setDisplayValue(parsedValue.toFixed(2));
            }
        };

        return (
            <InputGroup>
                <InputGroupAddon>
                    <InputGroupText>
                        {getCurrencySymbol(currencyCode)}
                    </InputGroupText>
                </InputGroupAddon>
                <InputGroupInput
                    ref={ref}
                    id={id}
                    type="text"
                    inputMode="decimal"
                    value={displayValue}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder={placeholder}
                    disabled={disabled}
                    required={required}
                />
                <InputGroupAddon align="inline-end">
                    <InputGroupText>{currencyCode}</InputGroupText>
                </InputGroupAddon>
            </InputGroup>
        );
    },
);

AmountInput.displayName = 'AmountInput';

