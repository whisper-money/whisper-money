import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepChoice } from './step-choice';

const options = [
    { value: 'head', title: 'In my head', description: 'Where most start' },
    { value: 'sheet', title: 'A spreadsheet', description: 'Bring it' },
];

const renderChoice = (value?: string, onSelect = vi.fn()) => {
    const onContinue = vi.fn();

    render(
        <StepChoice
            title="How do you keep track today?"
            description="No wrong answer."
            options={options}
            value={value}
            onSelect={onSelect}
            onContinue={onContinue}
        />,
    );

    return { onSelect, onContinue };
};

describe('StepChoice', () => {
    it('reports the row that was picked', () => {
        const { onSelect } = renderChoice();

        fireEvent.click(screen.getByText('A spreadsheet'));

        expect(onSelect).toHaveBeenCalledWith('sheet');
    });

    // The next step reads the answer back, so it has to have one.
    it('holds the action shut until something is picked', () => {
        renderChoice();

        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    });

    it('opens the action once an answer is in', () => {
        const { onContinue } = renderChoice('head');

        const action = screen.getByRole('button', { name: 'Continue' });
        expect(action).toBeEnabled();

        fireEvent.click(action);
        expect(onContinue).toHaveBeenCalled();
    });
});
