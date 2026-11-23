import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useFormatAmount } from './use-format-amount';

let isPrivacyModeEnabled = false;

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled }),
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

beforeEach(() => {
    isPrivacyModeEnabled = false;
});

describe('useFormatAmount', () => {
    it('formats an amount in its minor units, at the currency scale', () => {
        const { result } = renderHook(() => useFormatAmount());

        expect(result.current.formatAmount(123456, 'EUR')).toBe('€1,234.56');
        expect(result.current.formatAmount(123456, 'COP')).toBe('COP 123,456');
    });

    it('drops the decimals when asked', () => {
        const { result } = renderHook(() => useFormatAmount());

        expect(
            result.current.formatAmount(123456, 'EUR', { fractionDigits: 0 }),
        ).toBe('€1,235');
    });

    it('masks the figures when privacy mode is on', () => {
        isPrivacyModeEnabled = true;
        const { result } = renderHook(() => useFormatAmount());

        expect(result.current.formatAmount(123456, 'EUR')).toBe('€*,***.**');
        expect(result.current.maskAmount('1.2K')).toBe('*.*K');
    });

    it('leaves a caller-formatted number alone when privacy mode is off', () => {
        const { result } = renderHook(() => useFormatAmount());

        expect(result.current.maskAmount('1.2K')).toBe('1.2K');
    });
});
