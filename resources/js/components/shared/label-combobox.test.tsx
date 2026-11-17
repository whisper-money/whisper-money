import type { Label } from '@/types/label';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { LabelCombobox } from './label-combobox';

beforeAll(() => {
    globalThis.ResizeObserver ??= class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    Element.prototype.scrollIntoView = vi.fn();
});

function makeLabel(id: string, name: string, archivedAt: string | null = null) {
    return {
        id,
        user_id: 'user-1',
        name,
        color: 'blue',
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        deleted_at: null,
        archived_at: archivedAt,
    } as Label;
}

const travel = makeLabel('travel', 'Travel');
const oldFlat = makeLabel('old-flat', 'Old flat', '2026-02-01T00:00:00Z');

function renderCombobox(value: string[], onValueChange = vi.fn()) {
    render(
        <LabelCombobox
            value={value}
            onValueChange={onValueChange}
            labels={[travel, oldFlat]}
        />,
    );

    return onValueChange;
}

function openPicker() {
    fireEvent.click(screen.getByTestId('label-combobox-trigger'));
}

describe('LabelCombobox with archived labels', () => {
    it('keeps an archived label already attached as a removable chip', () => {
        const onValueChange = renderCombobox(['old-flat']);
        const trigger = screen.getByTestId('label-combobox-trigger');

        expect(within(trigger).getByText('Old flat')).toBeInTheDocument();

        fireEvent.click(
            within(within(trigger).getByText('Old flat')).getByRole('button'),
        );

        expect(onValueChange).toHaveBeenCalledWith([]);
    });

    it('never offers an archived label as an option', () => {
        renderCombobox(['old-flat']);

        openPicker();

        expect(
            screen
                .getAllByTestId('label-option')
                .map((option) => option.dataset.labelName),
        ).toEqual(['Travel']);
    });

    it('explains an archived name instead of offering to create it', () => {
        renderCombobox([]);

        openPicker();
        fireEvent.change(
            screen.getByPlaceholderText('Search or create labels...'),
            {
                target: { value: 'old flat' },
            },
        );

        expect(screen.getByTestId('label-archived-hint')).toHaveTextContent(
            'The label “Old flat” is archived.',
        );
        expect(screen.queryByTestId('label-create-option')).toBeNull();
    });
});
