import { type Label } from '@/types/label';
import { describe, expect, it } from 'vitest';
import {
    labelComboboxOptions,
    normalizeLabelComboboxState,
} from './label-combobox-state';

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

function label(id: string, name: string, archivedAt: string | null = null) {
    return {
        id,
        user_id: 'user-1',
        name,
        color: 'green',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        deleted_at: null,
        archived_at: archivedAt,
    } as Label;
}

describe('labelComboboxOptions', () => {
    const travel = label('l-1', 'Travel');
    const groceries = label('l-2', 'Groceries');
    const oldFlat = label('l-3', 'Old flat', '2026-02-01T00:00:00Z');
    const labels = [travel, oldFlat, groceries];

    it('offers only assignable labels, sorted by name', () => {
        const { options } = labelComboboxOptions([], labels, '');

        expect(options.map((option) => option.name)).toEqual([
            'Groceries',
            'Travel',
        ]);
    });

    it('keeps an archived label that is already selected as a chip', () => {
        const { selectedLabels, options } = labelComboboxOptions(
            ['l-3', 'l-1'],
            labels,
            '',
        );

        expect(selectedLabels.map((selected) => selected.id)).toEqual([
            'l-1',
            'l-3',
        ]);
        expect(options.map((option) => option.id)).not.toContain('l-3');
    });

    it('filters options by the query', () => {
        const { options } = labelComboboxOptions([], labels, ' gro ');

        expect(options.map((option) => option.name)).toEqual(['Groceries']);
    });

    it('flags an archived label matching the query so it is not created again', () => {
        const result = labelComboboxOptions([], labels, 'old FLAT');

        expect(result.options).toEqual([]);
        expect(result.archivedMatch?.id).toBe('l-3');
        expect(result.nameTaken).toBe(true);
    });

    it('treats an unknown name as free to create', () => {
        const result = labelComboboxOptions([], labels, 'Gifts');

        expect(result.archivedMatch).toBeNull();
        expect(result.nameTaken).toBe(false);
    });
});
