import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { StepAiSuggestions } from './step-ai-suggestions';

// Keep the initial state request pending so the component stays in its
// "generating" branch for the duration of the test.
vi.mock('axios', () => ({
    default: {
        get: () => new Promise(() => {}),
        post: () => new Promise(() => {}),
        isAxiosError: () => false,
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
}));

describe('StepAiSuggestions generating state', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function renderStep() {
        return render(
            <StepAiSuggestions categories={[]} onComplete={vi.fn()} />,
        );
    }

    it('renders skeleton cards and the duration hint while generating', () => {
        renderStep();

        expect(
            screen.getByText('This can take up to two minutes.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Analysing your transactions…'),
        ).toBeInTheDocument();
    });

    it('advances through the status messages over time', () => {
        renderStep();

        expect(
            screen.getByText('Analysing your transactions…'),
        ).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(3500);
        });
        expect(screen.getByText('Finding related groups…')).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(3500);
        });
        expect(
            screen.getByText('Finding the right categories…'),
        ).toBeInTheDocument();
    });

    it('holds on the final message instead of looping back', () => {
        renderStep();

        act(() => {
            vi.advanceTimersByTime(3500 * 10);
        });

        expect(
            screen.getByText('Polishing your suggestions…'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Analysing your transactions…'),
        ).not.toBeInTheDocument();
    });
});
