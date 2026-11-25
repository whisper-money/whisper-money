import { transactionSyncService } from '@/services/transaction-sync';
import { type DecryptedTransaction } from '@/types/transaction';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { AxiosError, type AxiosResponse } from 'axios';
import { type ComponentProps } from 'react';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SplitTransactionDialog } from './split-transaction-dialog';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { split: vi.fn(async () => []) },
}));

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

// The pickers have tests of their own; here they only say what they hold.
vi.mock('@/components/transactions/category-select', () => ({
    CategorySelect: ({ value }: { value: string }) => (
        <div data-testid="split-category" data-value={value} />
    ),
}));
vi.mock('@/components/shared/label-combobox', () => ({
    LabelCombobox: ({ value }: { value: string[] }) => (
        <div data-testid="split-labels" data-value={value.join(',')} />
    ),
}));

const supermarket = {
    id: 'tx-1',
    description: 'SUPERMARKET RUN',
    description_iv: null,
    decryptedDescription: 'SUPERMARKET RUN',
    amount: -5000,
    currency_code: 'EUR',
    transaction_date: '2026-10-01',
    label_ids: ['label-1', 'label-2'],
} as DecryptedTransaction;

type Props = ComponentProps<typeof SplitTransactionDialog>;

function props(overrides: Partial<Props> = {}): Props {
    return {
        transaction: supermarket,
        categories: [],
        labels: [],
        open: true,
        onOpenChange: vi.fn(),
        onSuccess: vi.fn(),
        ...overrides,
    };
}

const submitButton = () =>
    screen.getByTestId('submit-split') as HTMLButtonElement;

const amountField = (index: number) =>
    document.getElementById(`split-amount-${index}`) as HTMLInputElement;

function typeAmount(index: number, value: string) {
    const input = amountField(index);
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value } });
}

describe('SplitTransactionDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('sends the parts of an expense as money out, each with the original labels', async () => {
        const onSuccess = vi.fn();
        const onOpenChange = vi.fn();
        render(
            <SplitTransactionDialog {...props({ onSuccess, onOpenChange })} />,
        );

        expect(
            screen
                .getAllByTestId('split-labels')
                .map((picker) => picker.dataset.value),
        ).toEqual(['label-1,label-2', 'label-1,label-2']);

        typeAmount(0, '30');
        typeAmount(1, '20');
        fireEvent.click(submitButton());

        await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());
        expect(transactionSyncService.split).toHaveBeenCalledWith('tx-1', [
            {
                amount: -3000,
                category_id: null,
                label_ids: ['label-1', 'label-2'],
            },
            {
                amount: -2000,
                category_id: null,
                label_ids: ['label-1', 'label-2'],
            },
        ]);
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('sends the parts of an income as money in', async () => {
        const onSuccess = vi.fn();
        render(
            <SplitTransactionDialog
                {...props({
                    transaction: {
                        ...supermarket,
                        amount: 5000,
                        label_ids: [],
                    },
                    onSuccess,
                })}
            />,
        );

        typeAmount(0, '30');
        typeAmount(1, '20');
        fireEvent.click(submitButton());

        await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());
        expect(
            vi
                .mocked(transactionSyncService.split)
                .mock.calls[0][1].map((part) => part.amount),
        ).toEqual([3000, 2000]);
    });

    it('keeps Submit disabled until the parts add up to the original', () => {
        render(<SplitTransactionDialog {...props()} />);

        expect(submitButton().disabled).toBe(true);

        typeAmount(0, '30');
        expect(submitButton().disabled).toBe(true);
        expect(screen.getByText('Still to share out: €20.00')).toBeTruthy();

        typeAmount(1, '25');
        expect(submitButton().disabled).toBe(true);
        expect(screen.getByText('Over by €5.00')).toBeTruthy();

        typeAmount(1, '20');
        expect(submitButton().disabled).toBe(false);
        expect(screen.getByText('All shared out')).toBeTruthy();
    });

    it('sends one split however often the form is submitted while it is on its way', async () => {
        let finish: () => void = () => {};
        vi.mocked(transactionSyncService.split).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    finish = () => resolve([]);
                }),
        );
        const onSuccess = vi.fn();
        render(<SplitTransactionDialog {...props({ onSuccess })} />);

        typeAmount(0, '30');
        typeAmount(1, '20');
        const form = submitButton().closest('form')!;
        fireEvent.submit(form);
        fireEvent.submit(form);

        expect(submitButton().disabled).toBe(true);
        expect(submitButton().textContent).toBe('Splitting...');

        finish();

        await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());
        expect(transactionSyncService.split).toHaveBeenCalledOnce();
    });

    it("shows the server's refusal and stays open with what was typed", async () => {
        vi.mocked(transactionSyncService.split).mockRejectedValueOnce(
            new AxiosError(
                'Request failed',
                'ERR_BAD_REQUEST',
                undefined,
                null,
                {
                    status: 422,
                    data: { message: 'This transaction cannot be split.' },
                } as AxiosResponse,
            ),
        );
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const onOpenChange = vi.fn();
        const onSuccess = vi.fn();
        render(
            <SplitTransactionDialog {...props({ onOpenChange, onSuccess })} />,
        );

        typeAmount(0, '30');
        typeAmount(1, '20');
        fireEvent.click(submitButton());

        await waitFor(() =>
            expect(toast.error).toHaveBeenCalledWith(
                'This transaction cannot be split.',
            ),
        );
        expect(onOpenChange).not.toHaveBeenCalled();
        expect(onSuccess).not.toHaveBeenCalled();
        expect(amountField(0).value).toBe('30');
        expect(amountField(1).value).toBe('20');
        expect(screen.getByText('All shared out')).toBeTruthy();
        expect(submitButton().disabled).toBe(false);
    });

    it('opens on empty parts again after it was closed', () => {
        const { rerender } = render(<SplitTransactionDialog {...props()} />);

        typeAmount(0, '30');
        fireEvent.click(screen.getByRole('button', { name: 'Add split' }));
        expect(screen.getAllByTestId('split-labels')).toHaveLength(3);

        rerender(<SplitTransactionDialog {...props({ open: false })} />);
        rerender(<SplitTransactionDialog {...props()} />);

        expect(screen.getAllByTestId('split-labels')).toHaveLength(2);
        expect(amountField(0).value).toBe('');
        expect(screen.getByText('Still to share out: €50.00')).toBeTruthy();
    });

    it('shows a still-encrypted original as the placeholder, never its ciphertext', () => {
        render(
            <SplitTransactionDialog
                {...props({
                    transaction: {
                        ...supermarket,
                        description: 'q8Zr3vX0cipher==',
                        description_iv: 'iv-1',
                        decryptedDescription: 'q8Zr3vX0cipher==',
                    },
                })}
            />,
        );

        expect(screen.getByText('••••••••••')).toBeTruthy();
        expect(screen.queryByText(/q8Zr3vX0cipher/)).toBeNull();
    });
});
