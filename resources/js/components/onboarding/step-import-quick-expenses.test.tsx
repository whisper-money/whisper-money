import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepImportQuickExpenses } from './step-import-quick-expenses';

const { post, captureEvent, isMobile } = vi.hoisted(() => ({
    post: vi.fn(),
    captureEvent: vi.fn(),
    isMobile: { value: false },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => isMobile.value }));

vi.mock('axios', () => ({
    default: {
        post,
        isAxiosError: (error: unknown) =>
            typeof error === 'object' && error !== null && 'response' in error,
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'en-US', auth: { user: { currency_code: 'EUR' } } },
    }),
}));

type Props = Parameters<typeof StepImportQuickExpenses>[0];

function renderStep(overrides: Partial<Props> = {}) {
    const onComplete = vi.fn();
    const onUpload = vi.fn();

    render(
        <StepImportQuickExpenses
            accountId="account-1"
            currencyCode="EUR"
            onComplete={onComplete}
            onUpload={onUpload}
            source="upload"
            {...overrides}
        />,
    );

    return { onComplete, onUpload };
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
        isMobile.value = false;
    });

    it('reports where it was opened from, once', () => {
        renderStep({ source: 'default' });

        type('Rent', '900');

        expect(captureEvent).toHaveBeenCalledOnce();
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_quick_expenses_opened',
            { source: 'default' },
        );
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

        type('Rent', '900');
        type('Eating out', '120,50');

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save 2 expenses' }),
            );
        });

        expect(post).toHaveBeenCalledWith('/onboarding/quick-expenses', {
            account_id: 'account-1',
            expenses: [
                { kind: 'rent', amount: 90000 },
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
        expect(captureEvent).not.toHaveBeenCalledWith(
            'onboarding_quick_expenses_submitted',
            expect.anything(),
        );
    });

    it('says why when the server refuses the account', async () => {
        post.mockRejectedValue({
            response: {
                status: 422,
                data: { message: 'This account cannot take movements.' },
            },
        });
        renderStep();

        type('Transport', '60');

        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Save 1 expense' }),
            );
        });

        expect(
            screen.getByText('This account cannot take movements.'),
        ).toBeInTheDocument();
    });

    it('goes to the file for someone who has it after all', () => {
        const { onUpload } = renderStep();

        fireEvent.click(
            screen.getByRole('button', { name: /Have the bank file\?/ }),
        );

        expect(onUpload).toHaveBeenCalledOnce();
    });

    it('lets the step go on without saving anything', () => {
        const { onComplete } = renderStep();

        type('Rent', '900');
        fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }));

        expect(onComplete).toHaveBeenCalledOnce();
        expect(post).not.toHaveBeenCalled();
    });

    it('offers the link to a computer on a phone only', () => {
        const importLink = { accountId: 'account-1', onSent: vi.fn() };
        const { unmount } = render(
            <StepImportQuickExpenses
                accountId="account-1"
                currencyCode="EUR"
                onComplete={vi.fn()}
                importLink={importLink}
                source="upload"
            />,
        );

        expect(screen.queryByText('Rather do it on a computer?')).toBeNull();
        unmount();

        isMobile.value = true;
        renderStep({ importLink });

        expect(
            screen.getByRole('button', {
                name: /Email me a link to import from there/,
            }),
        ).toBeInTheDocument();
    });
});

describe('StepImportQuickExpenses counter', () => {
    it('counts the figures up against the guess', () => {
        renderStep({ spendingGuess: 200000 });

        expect(
            screen.getByText('of the €2,000 you guessed'),
        ).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '0',
        );

        type('Rent', '900');
        type('Groceries', '100');

        expect(screen.getByText('€1,000')).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '50',
        );
    });

    it('says so once the figures are past the guess', () => {
        renderStep({ spendingGuess: 100000 });

        type('Rent', '1200,40');

        expect(screen.getByText(/Already past your guess/)).toBeInTheDocument();
        expect(screen.getByText('€1,200')).toBeInTheDocument();
        expect(screen.getByText('guess €1,000')).toBeInTheDocument();
        expect(screen.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '100',
        );
    });

    it('is not shown without a guess', () => {
        renderStep();

        expect(screen.queryByRole('progressbar')).toBeNull();
    });

    // The guess was made in the user's currency, not the account's.
    it('is not shown when the account is in another currency', () => {
        renderStep({ spendingGuess: 200000, currencyCode: 'USD' });

        expect(screen.queryByRole('progressbar')).toBeNull();
    });
});
