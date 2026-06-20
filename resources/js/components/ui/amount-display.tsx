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
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const locale = useLocale();
    const isPositive = amountInCents > 0;

    const formatted = useMemo(() => {
        return formatCurrency(amountInCents, currencyCode, locale, minimumFractionDigits, maximumFractionDigits);
    }, [locale, amountInCents, currencyCode, minimumFractionDigits, maximumFractionDigits]);

    const getBackgroundClass = () => {
        if (highlightPositive && variant === 'positive-highlight') {
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
                getBackgroundClass(),
                { 'font-mono tabular-nums': monospace },
                className,
            )}
        >
            <span className="text-xs">{showSign && isPositive && '+'}</span>
            <span>{isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted}</span>
        </span>
    );
}

