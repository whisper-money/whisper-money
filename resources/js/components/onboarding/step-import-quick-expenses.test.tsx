import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepImportQuickExpenses } from './step-import-quick-expenses';

const { post, captureEvent } = vi.hoisted(() => ({
    post: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('axios', () => ({
    default: { post, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

function renderStep() {
    const onComplete = vi.fn();
    const onBack = vi.fn();

    render(
        <StepImportQuickExpenses
            accountId="account-1"
            currencyCode="EUR"
            onComplete={onComplete}
            onBack={onBack}
        />,
    );

    return { onComplete, onBack };
}

function type(label: string, value: string) {
    const input = screen.getByLabelText(label);
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value } });
}

describe('StepImportQuickExpenses', () => {
    afterEach(() => {
        post.mockReset();
        captureEvent.mockReset();
    });

    it('asks for at least one expense before it saves anything', () => {
        renderStep();

        expect(
            screen.getByRole('button', { name: 'Save 0 expenses' }),
        ).toBeDisabled();

        type('Groceries', '350');

        expect(
            screen.getByRole('button', { name: 'Save 1 expense' }),
        ).toBeEnabled();
    });

    it('sends the filled-in expenses in minor units and reports only how many', async () => {
        post.mockResolvedValue({ data: { count: 2 } });
        const { onComplete } = renderStep();

        type('Rent or mortgage', '900');
        type('Eating out', '120,50');

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save 2 expenses' }),
            );
        });

        expect(post).toHaveBeenCalledWith('/onboarding/quick-expenses', {
            account_id: 'account-1',
            expenses: [
                { kind: 'housing', amount: 90000 },
                { kind: 'eating_out', amount: 12050 },
            ],
        });
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_quick_expenses_submitted',
            { count: 2 },
        );
        expect(onComplete).toHaveBeenCalledOnce();
    });

    it('stays on the screen and says so when the save fails', async () => {
        post.mockRejectedValue(new Error('500'));
        const { onComplete } = renderStep();

        type('Transport', '60');

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save 1 expense' }),
            );
        });

        expect(
            screen.getByText("We couldn't save them. Please try again."),
        ).toBeInTheDocument();
        expect(onComplete).not.toHaveBeenCalled();
        expect(captureEvent).not.toHaveBeenCalled();
    });

    it('goes back to the file', () => {
        const { onBack } = renderStep();

        fireEvent.click(
            screen.getByRole('button', { name: 'Back to the file' }),
        );

        expect(onBack).toHaveBeenCalledOnce();
    });
});
