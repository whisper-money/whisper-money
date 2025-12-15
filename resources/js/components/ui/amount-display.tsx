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
    const { isKeySet } = useEncryptionKey();
    const [amount, setAmount] = useState<number>(amountInCents / 100);

    const shouldHideAmount = isPrivacyModeEnabled || !isKeySet

    useEffect(() => {
        if (shouldHideAmount) {
            const length = Math.min(3, amountInCents.toString().length);
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

    return (
        <span className={cn('transition-all duration-300', { 'rounded-xs bg-foreground': shouldHideAmount }, className)}>
            {showSign && amount >= 0 && '+'}
            {formatted}
        </span>
    );
}

