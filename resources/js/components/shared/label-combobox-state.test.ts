import { describe, expect, it } from 'vitest';
import { normalizeLabelComboboxState } from './label-combobox-state';

describe('normalizeLabelComboboxState', () => {
    it('falls back to empty arrays when value or labels are missing', () => {
        expect(normalizeLabelComboboxState(null, undefined)).toEqual({
            value: [],
            labels: [],
        });
    });

    it('keeps provided arrays intact', () => {
        const value = ['label-1'];
        const labels = [
            {
                id: 'label-1',
                user_id: 'user-1',
                name: 'Groceries',
                color: 'green',
                created_at: '2026-01-01T00:00:00Z',
                updated_at: '2026-01-01T00:00:00Z',
                deleted_at: null,
            },
        ];

        expect(normalizeLabelComboboxState(value, labels)).toEqual({
            value,
            labels,
        });
    });
});
