import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { cn } from '@/lib/utils';

interface AmountDisplayProps {
    amountInCents: number;
    currencyCode: string;
    className?: string;
    showSign?: boolean;
    minimumFractionDigits?: number;
    maximumFractionDigits?: number;
}

export function AmountDisplay({
    amountInCents,
    currencyCode,
    className,
    showSign = false,
    minimumFractionDigits = 2,
    maximumFractionDigits = 2,
}: AmountDisplayProps) {
    const { isPrivacyModeEnabled } = usePrivacyMode();

    const amount = amountInCents / 100;

    const formatted = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits,
        maximumFractionDigits,
    }).format(amount);

    if (isPrivacyModeEnabled) {
        const currencySymbol = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currencyCode,
        })
            .formatToParts(0)
            .find((part) => part.type === 'currency')?.value || '$';

        return (
            <span
                className={cn(
                    'blur-sm transition-all duration-300',
                    className,
                )}
            >
                {currencySymbol}•••.••
            </span>
        );
    }

    return (
        <span className={cn('transition-all duration-300', className)}>
            {showSign && amount >= 0 && '+'}
            {formatted}
        </span>
    );
}

