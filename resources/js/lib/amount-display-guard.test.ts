/**
 * Money renders through AmountDisplay (docs/design-system.md, #1360).
 *
 * `<AmountDisplay>` formats an amount at its currency's scale and masks it in
 * privacy mode; `useFormatAmount()` does the same for the places a node cannot
 * go (a translated sentence, an `aria-label`, a chart tick). An ad-hoc
 * `formatCurrency(...)` or `Intl.NumberFormat({ style: 'currency' })` skips
 * the masking, which is how amounts leaked with privacy mode on.
 *
 * So this test fails when a file under resources/js calls `formatCurrency(`
 * or formats with `style: 'currency'` or `notation: 'compact'`, unless it is
 * listed below with the reason it may.
 */

import { describe, expect, it } from 'vitest';
import {
    findViolations,
    MARKETING_PAGES,
    missingPaths,
    type ForbiddenPattern,
} from './source-guard';

/** Relative to resources/js. A trailing slash allows a whole folder. */
const ALLOWED: readonly string[] = [
    // The formatters themselves, and the two places that mask them.
    'utils/currency.ts',
    'components/ui/amount-display.tsx',
    'hooks/use-format-amount.ts',
    // Reads the currency symbol and its side of the number for an input: what
    // the user is typing is not masked.
    'components/ui/amount-input.tsx',
    // The onboarding guess is a slider the user is dragging; its fixed bounds
    // are not their money and masking its value would hide the control.
    'components/onboarding/step-guess.tsx',
    // Recognises currency symbols in an imported file; renders nothing.
    'lib/full-import-money-pro.ts',
    // A sample amount that shows each region's separators in the locale picker.
    'utils/format-locale.ts',
    // Whisper Money's own prices: not the user's money, privacy mode keeps
    // them readable.
    'components/onboarding/step-accounts-hub.tsx',
    'components/subscription/cancel-flow-offer-step.tsx',
    'components/subscription/paused-plan-notice.tsx',
    'components/subscription/plan-picker.tsx',
    'components/subscription/trial-ending-banner.tsx',
    'pages/settings/billing.tsx',
    // The widget SDK's money formatter; the SDK masks it its own way
    // (widgets/sdk/amount.tsx), from the host's privacy mode.
    'widgets/sdk/format-money.ts',
    // Marketing and legal pages have their own look and sample figures.
    ...MARKETING_PAGES,
];

const FORBIDDEN: readonly ForbiddenPattern[] = [
    { pattern: /\bformatCurrency\(/, what: 'calls formatCurrency(' },
    {
        pattern: /\bstyle\s*[:=]\s*['"`]currency['"`]/,
        what: "formats with style: 'currency'",
    },
    {
        // Compact chart ticks: useFormatAmount().formatCompactAmount masks them.
        pattern: /\bnotation\s*:\s*['"`]compact['"`]/,
        what: "formats with notation: 'compact'",
    },
];

describe('amount display guard', () => {
    it('renders money through AmountDisplay or useFormatAmount', () => {
        expect(
            findViolations(FORBIDDEN, ALLOWED),
            'Render money with <AmountDisplay>, or useFormatAmount() where it must be a string, so privacy mode masks it',
        ).toEqual([]);
    });

    it('allows only files that exist', () => {
        expect(missingPaths(ALLOWED)).toEqual([]);
    });
});
