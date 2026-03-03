import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { formatCurrency } from '@/utils/currency';
import { useMemo } from 'react';

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
    const { isKeySet } = useEncryptionKey();
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const locale = useLocale();
    const isPositive = amountInCents > 0;

    const shouldHideAmount = !isKeySet;

    const displayAmountInCents = useMemo(() => {
        if (shouldHideAmount) {
            const length = Math.max(3, amountInCents.toString().length);
            return parseInt('8'.repeat(length - 2) + '00');
        }

        return amountInCents;
    }, [amountInCents, shouldHideAmount]);

    const formatted = useMemo(() => {
        return formatCurrency(displayAmountInCents, currencyCode, locale, minimumFractionDigits, maximumFractionDigits);
    }, [locale, displayAmountInCents, currencyCode, minimumFractionDigits, maximumFractionDigits]);

    const getBackgroundClass = (shouldHideAmount: boolean) => {
        if (!highlightPositive && !shouldHideAmount) return '';

        if (shouldHideAmount) {
            if (variant === 'positive-highlight' && isPositive) {
                return 'rounded-xs bg-green-400 dark:bg-green-900 text-green-400 dark:text-green-900 opacity-20 dark:opacity-100';
            }

            return 'rounded-xs bg-zinc-950 dark:bg-zinc-700 dark:text-zinc-700';
        }

        if (variant === 'positive-highlight') {
            return 'bg-green-100/70 dark:bg-green-900';
        }

        return '';
    };

    return (
        <span
            className={cn(
                'inline',
                'transition-all duration-300',
                variantStyles[variant],
                size && sizeStyles[size],
                weight && weightStyles[weight],
                getBackgroundClass(shouldHideAmount),
                { 'font-mono tabular-nums': monospace },
                className,
            )}
        >
            <span className="text-xs">{showSign && isPositive && '+'}</span>
            <span>{isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted}</span>
        </span>
    );
}

