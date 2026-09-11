import { formatCurrency } from '@/utils/currency';
import { formatDate } from '@/utils/date';

/**
 * A fixed sample rather than today's date. The point of the example is the
 * order of the fields, and on the 5th of May every region on the list prints
 * the same thing; a day past the 12th can never be read as a month.
 */
const SAMPLE_DATE = '2026-12-25';

/** 1234.56 in minor units — enough digits to show the grouping separator too. */
const SAMPLE_AMOUNT = 123456;

export interface FormatLocaleOption {
    code: string;
    label: string;
}

/**
 * The picker's rows: the country, then the same amount and date written the way
 * that region writes them, in the reader's own currency.
 *
 * The example is the whole reason this reads as a choice rather than a list of
 * codes — nobody knows what `es-419` does to a number, everybody can read
 * "1,234.56". Two countries printing the same example is the honest answer, not
 * a reason to hide one of them.
 *
 * `Intl` owns the country names, the way `useConnectCountries()` already has
 * them, so 43 of them never need a translation entry.
 */
export function formatLocaleOptions(
    locales: string[],
    currencyCode: string,
    displayIn: string,
): FormatLocaleOption[] {
    const countries = new Intl.DisplayNames([displayIn], { type: 'region' });
    const collator = new Intl.Collator(displayIn);

    return locales
        .map((code) => {
            const region = code.split('-')[1] ?? code;
            const amount = formatCurrency(SAMPLE_AMOUNT, currencyCode, code);
            const date = formatDate(SAMPLE_DATE, 'd/M/yyyy', code);

            return {
                code,
                label: `${countries.of(region) ?? code} — ${amount} · ${date}`,
            };
        })
        .sort((a, b) => collator.compare(a.label, b.label));
}
