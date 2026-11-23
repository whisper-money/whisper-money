import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useLocale } from '@/hooks/use-locale';
import {
    formatAmountNumber,
    formatCompactAmount,
    formatCurrency,
    maskDigits,
} from '@/utils/currency';
import { useMemo } from 'react';

export interface FormatAmountOptions {
    /**
     * Overrides the currency's own scale on both ends, for figures that
     * deliberately drop the decimals (onboarding sentences, chart labels).
     */
    fractionDigits?: number;
}

/** Every method masks the figures when privacy mode is on. */
export interface AmountFormatter {
    /**
     * An amount in its currency's minor units as a string ("€1,234.56"). For
     * the places a node cannot go: a translated sentence, an `aria-label`, a
     * `title`, a chart tooltip string.
     */
    formatAmount: (
        amountInMinorUnits: number,
        currencyCode: string,
        options?: FormatAmountOptions,
    ) => string;
    /**
     * The same amount without its symbol ("1,234.56"), for a layout that sets
     * the symbol apart or a column whose rows mix currencies.
     */
    formatAmountNumber: (
        amountInMinorUnits: number,
        currencyCode: string,
        options?: FormatAmountOptions,
    ) => string;
    /** A short chart-axis label: "12K", or "€12K" with `withSymbol`. */
    formatCompactAmount: (
        amountInMinorUnits: number,
        currencyCode: string,
        options?: { withSymbol?: boolean },
    ) => string;
    /** Masks a figure that is already a string (a chart's own fallback). */
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
            formatAmountNumber: (
                amountInMinorUnits,
                currencyCode,
                options = {},
            ) =>
                maskAmount(
                    formatAmountNumber(
                        amountInMinorUnits,
                        currencyCode,
                        locale,
                        options.fractionDigits,
                    ),
                ),
            formatCompactAmount: (
                amountInMinorUnits,
                currencyCode,
                options = {},
            ) =>
                maskAmount(
                    formatCompactAmount(
                        amountInMinorUnits,
                        currencyCode,
                        locale,
                        options.withSymbol,
                    ),
                ),
        };
    }, [isPrivacyModeEnabled, locale]);
}
