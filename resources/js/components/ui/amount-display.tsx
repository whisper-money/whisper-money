import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { cn } from '@/lib/utils';
import { useEffect, useMemo, useState } from 'react';

interface AmountDisplayProps {
    amountInCents: number;
    currencyCode: string;
    className?: string;
    showSign?: boolean;
    minimumFractionDigits?: number;
    maximumFractionDigits?: number;
    variant?: 'default' | 'positive-highlight' | 'trend' | 'large' | 'compact';
    size?: 'xs' | 'sm' | 'base' | 'lg' | 'xl' | '2xl' | '4xl';
    weight?: 'normal' | 'medium' | 'semibold' | 'bold';
    monospace?: boolean;
    highlightPositive?: boolean;
}

const variantStyles = {
    default: '',
    'positive-highlight': 'px-1 rounded',
    trend: '',
    large: 'text-2xl sm:text-4xl font-semibold tabular-nums',
    compact: 'text-sm font-semibold tabular-nums',
};

const sizeStyles = {
    xs: 'text-xs',
    sm: 'text-sm',
    base: 'text-base',
    lg: 'text-lg',
    xl: 'text-xl',
    '2xl': 'text-2xl',
    '4xl': 'text-4xl',
};

const weightStyles = {
    normal: 'font-normal',
    medium: 'font-medium',
    semibold: 'font-semibold',
    bold: 'font-bold',
};

export function AmountDisplay({
    amountInCents,
    currencyCode,
    className,
    showSign = false,
    minimumFractionDigits = 2,
    maximumFractionDigits = 2,
    variant = 'default',
    size,
    weight,
    monospace = false,
    highlightPositive = false,
}: AmountDisplayProps) {
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const { isKeySet } = useEncryptionKey();
    const [amount, setAmount] = useState<number>(amountInCents / 100);

    const shouldHideAmount = isPrivacyModeEnabled || !isKeySet;

    useEffect(() => {
        if (shouldHideAmount) {
            const length = Math.max(3, amountInCents.toString().length);
            const fakeAmountStr = parseInt("8".repeat(length - 2) + "00");

            setAmount(fakeAmountStr / 100);
            return;
        }

        setAmount(amountInCents / 100);
    }, [amountInCents, shouldHideAmount]);

    const formatted = useMemo(() => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currencyCode,
            minimumFractionDigits,
            maximumFractionDigits,
        }).format(amount);
    }, [amount, currencyCode, minimumFractionDigits, maximumFractionDigits]);

    const getBackgroundClass = (shouldHideAmount: boolean) => {
        if (!highlightPositive && !shouldHideAmount) return '';

        if (shouldHideAmount) {
            if (variant === 'positive-highlight') {
                return 'rounded-xs bg-green-400 dark:bg-green-900 text-green-400 dark:text-green-900 opacity-20 dark:opacity-100';
            }

            return 'rounded-xs bg-foreground'
        }

        if (variant === 'positive-highlight') {
            return 'bg-green-100/70 dark:bg-green-900';
        }

        return '';
    };

    return (
        <div
            className={cn(
                'inline',
                'transition-all duration-300',
                variantStyles[variant],
                size && sizeStyles[size],
                weight && weightStyles[weight],
                getBackgroundClass(shouldHideAmount),
                'font-mono tabular-nums',
                className
            )}
        >
            <span className='text-xs'>{showSign && amount >= 0 && '+'}</span>
            <span>{formatted}</span>
        </div>
    );
}

