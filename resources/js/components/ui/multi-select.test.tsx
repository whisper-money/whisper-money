import { listedOptions } from '@/lib/category-tree.fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { MultiSelect, type MultiSelectOption } from './multi-select';

const options: MultiSelectOption[] = [
    { value: 'food', label: 'Food', depth: 0, parentValue: null },
    { value: 'groceries', label: 'Groceries', depth: 1, parentValue: 'food' },
    { value: 'restaurants', label: 'Restaurants', depth: 1, parentValue: 'food' },
    { value: 'transport', label: 'Transport', depth: 0, parentValue: null },
];

describe('MultiSelect tree search', () => {
    it('lists the children of a parent option that matches the search', () => {
        const onChange = vi.fn();
        render(
            <MultiSelect options={options} selected={[]} onChange={onChange} />,
        );

        fireEvent.click(screen.getByRole('combobox'));
        fireEvent.change(screen.getByPlaceholderText('Search…'), {
            target: { value: 'food' },
        });

        expect(listedOptions()).toEqual(['Food', 'Groceries', 'Restaurants']);

        fireEvent.click(screen.getByText('Groceries'));
        expect(onChange).toHaveBeenCalledWith(['groceries']);
    });

    it('highlights one row at a time when two options share a label', () => {
        render(
            <MultiSelect
                options={[
                    { value: 'food', label: 'Food', parentValue: null },
                    { value: 'food-other', label: 'Other', parentValue: 'food' },
                    { value: 'car', label: 'Car', parentValue: null },
                    { value: 'car-other', label: 'Other', parentValue: 'car' },
                ]}
                selected={[]}
                onChange={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByRole('combobox'));
        const input = screen.getByPlaceholderText('Search…');
        fireEvent.change(input, { target: { value: 'other' } });
        fireEvent.keyDown(input, { key: 'ArrowDown' });

        expect(screen.getAllByRole('option', { selected: true })).toHaveLength(1);
    });
});
