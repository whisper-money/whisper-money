import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepImportBalances } from './step-import-balances';

vi.mock('@/lib/csrf', () => ({ getCsrfToken: () => 'test-token' }));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

const account = {
    id: 'account-1',
    name: 'Portfolio',
    type: 'investment' as const,
    currencyCode: 'EUR',
};

async function submit() {
    await act(async () => {
        fireEvent.submit(document.querySelector('#onboarding-balance')!);
    });
}

describe('StepImportBalances', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    // The step used to drop the figure it had just asked for and move on, which
    // left balance-only accounts empty on the dashboard.
    it('saves the balance before moving on', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true });
        global.fetch = fetchMock as unknown as typeof fetch;
        const onComplete = vi.fn();

        render(
            <StepImportBalances account={account} onComplete={onComplete} />,
        );

        const input = screen.getByLabelText('Current Balance');
        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: '1234.56' } });
        fireEvent.blur(input);
        await submit();

        expect(fetchMock).toHaveBeenCalledOnce();
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toContain(account.id);
        expect(options.method).toBe('POST');
        expect(JSON.parse(options.body)).toMatchObject({ balance: 123456 });
        expect(onComplete).toHaveBeenCalled();
    });

    it('keeps the user on the step when the balance could not be saved', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
            status: 422,
        }) as unknown as typeof fetch;
        const onComplete = vi.fn();

        render(
            <StepImportBalances account={account} onComplete={onComplete} />,
        );

        await submit();

        expect(onComplete).not.toHaveBeenCalled();
        expect(
            screen.getByText('Failed to set balance. Please try again.'),
        ).not.toBeNull();
    });

    // Deep-linked back to this step, the account it belongs to is gone: there
    // is nothing to save, and nothing to hold the user here for.
    it('moves on without a request when there is no account', async () => {
        const fetchMock = vi.fn();
        global.fetch = fetchMock as unknown as typeof fetch;
        const onComplete = vi.fn();

        render(
            <StepImportBalances account={undefined} onComplete={onComplete} />,
        );

        await submit();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(onComplete).toHaveBeenCalled();
    });
});
