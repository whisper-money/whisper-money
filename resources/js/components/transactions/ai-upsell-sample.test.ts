import { describe, expect, it } from 'vitest';
import { showsAiUpsell } from './ai-upsell-sample';

describe('showsAiUpsell', () => {
    it('is deterministic for the same id and rate', () => {
        const id = '019ec83c-9837-7245-8717-ae66b6dd9957';

        expect(showsAiUpsell(id, 33)).toBe(showsAiUpsell(id, 33));
    });

    it('shows nothing at 0 and everything at 100', () => {
        const id = '019ec83c-9837-7245-8717-ae66b6dd9957';

        expect(showsAiUpsell(id, 0)).toBe(false);
        expect(showsAiUpsell(id, 100)).toBe(true);
    });

    it('thresholds on the id last byte', () => {
        // 0x54 = 84, below the 33% cutoff (84.48) -> shown
        expect(showsAiUpsell('00000000-0000-0000-0000-000000000054', 33)).toBe(
            true,
        );
        // 0x55 = 85, at/above the cutoff -> hidden
        expect(showsAiUpsell('00000000-0000-0000-0000-000000000055', 33)).toBe(
            false,
        );
    });

    it('samples about the configured rate across all 256 final bytes', () => {
        const rateShown = (pct: number) =>
            Array.from({ length: 256 }, (_, n) =>
                showsAiUpsell(
                    `00000000-0000-0000-0000-0000000000${n.toString(16).padStart(2, '0')}`,
                    pct,
                ),
            ).filter(Boolean).length;

        expect(rateShown(33)).toBe(85); // bytes 0..84
        expect(rateShown(50)).toBe(128); // bytes 0..127
        expect(rateShown(75)).toBe(192); // bytes 0..191
    });
});
