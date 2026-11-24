import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { PercentageTrendIndicator } from './percentage-trend-indicator';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'es-ES' }));

// es-ES puts a no-break space before `%`; getByText normalises it to a
// plain space, so the expectations below use one.

describe('PercentageTrendIndicator', () => {
    it('writes the trend in the reader locale, like the amounts beside it', () => {
        render(<PercentageTrendIndicator trend={23.7} label="" />);

        expect(screen.getByText('+23,7 %')).toBeTruthy();
    });

    it('keeps the minus sign of a falling trend', () => {
        render(<PercentageTrendIndicator trend={-56} label="" />);

        expect(screen.getByText('-56,0 %')).toBeTruthy();
    });
});
