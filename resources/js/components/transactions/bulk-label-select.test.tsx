import { type Label } from '@/types/label';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { BulkLabelSelect } from './bulk-label-select';

const labels: Label[] = [
    {
        id: 'label-1',
        user_id: 'user-1',
        name: 'Work',
        color: 'blue',
        created_at: '2026-05-11T00:00:00.000000Z',
        updated_at: '2026-05-11T00:00:00.000000Z',
        deleted_at: null,
    },
];

describe('BulkLabelSelect', () => {
    it('asks for an empty label set when removing all labels', async () => {
        const onLabelsChange = vi.fn();

        render(
            <BulkLabelSelect labels={labels} onLabelsChange={onLabelsChange} />,
        );

        fireEvent.click(screen.getByRole('combobox'));
        fireEvent.click(await screen.findByText('Remove all labels'));

        expect(onLabelsChange).toHaveBeenCalledWith([]);
    });
});
