import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useLocale } from '@/hooks/use-locale';
import { formatCurrency, maskDigits } from '@/utils/currency';
import { useMemo } from 'react';

export interface FormatAmountOptions {
    /**
     * Overrides the currency's own scale on both ends, for figures that
     * deliberately drop the decimals (onboarding sentences, chart labels).
     */
    fractionDigits?: number;
}

export interface AmountFormatter {
    /**
     * An amount in its currency's minor units as a string, masked when privacy
     * mode is on. For the places a node cannot go: a translated sentence, an
     * `aria-label`, a `title`, a chart tick or tooltip string.
     */
    formatAmount: (
        amountInMinorUnits: number,
        currencyCode: string,
        options?: FormatAmountOptions,
    ) => string;
    /**
     * Masks the figures of a number the caller formatted itself (a compact
     * chart tick, a bare number in an import preview) when privacy mode is on.
     */
    maskAmount: (formatted: string) => string;
}

/**
 * The string form of `AmountDisplay`: the same formatting and the same privacy
 * masking, for an amount that has to be text. Render money in JSX with
 * `<AmountDisplay>`; reach for this only when the value must be a string.
 */
export function useFormatAmount(): AmountFormatter {
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const locale = useLocale();

    return useMemo(() => {
        const maskAmount = (formatted: string) =>
            isPrivacyModeEnabled ? maskDigits(formatted) : formatted;

        return {
            maskAmount,
            formatAmount: (amountInMinorUnits, currencyCode, options = {}) =>
                maskAmount(
                    formatCurrency(
                        amountInMinorUnits,
                        currencyCode,
                        locale,
                        options.fractionDigits,
                        options.fractionDigits,
                    ),
                ),
        };
    }, [isPrivacyModeEnabled, locale]);
}
