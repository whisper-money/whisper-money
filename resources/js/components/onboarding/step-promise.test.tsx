import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepPromise } from './step-promise';

describe('StepPromise', () => {
    it('promises the bank read when a bank is offered', () => {
        render(<StepPromise onContinue={vi.fn()} />);

        expect(
            screen.getByText('Your bank already has it. We just read it.'),
        ).toBeInTheDocument();
    });

    // The free plan, an install without open banking and a country no bank
    // of ours reaches all end on a hub with no bank row in it.
    it('promises the export instead when no bank is offered', () => {
        render(<StepPromise bankOffered={false} onContinue={vi.fn()} />);

        expect(
            screen.getByText(
                'Your bank already lets you export it. We read the file in one go.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Your bank already has it. We just read it.'),
        ).not.toBeInTheDocument();
    });
});
