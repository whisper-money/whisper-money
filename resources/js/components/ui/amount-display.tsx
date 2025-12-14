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
    const [amount, setAmount] = useState<number>(amountInCents / 100);

    useEffect(() => {
        if (isPrivacyModeEnabled) {
            // return a random amount with the same lenfth as amountInCents
            const length = amountInCents.toString().length;

            let randomAmountInCents = '';
            for (let i = 0; i < length; i++) {
                randomAmountInCents += Math.floor(Math.random() * 10).toString();
            }

            setAmount(parseInt(randomAmountInCents, 10) / 100)
            return;
        }

        setAmount(amountInCents / 100);
    }, [amountInCents, isPrivacyModeEnabled]);

    const formatted = useMemo(() => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currencyCode,
            minimumFractionDigits,
            maximumFractionDigits,
        }).format(amount);
    }, [amount, currencyCode, minimumFractionDigits, maximumFractionDigits]);

    return (
        <span className={cn('transition-all duration-300', { 'blur-sm': isPrivacyModeEnabled }, className)}>
            {showSign && amount >= 0 && '+'}
            {formatted}
        </span>
    );
}

