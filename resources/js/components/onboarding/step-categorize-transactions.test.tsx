import { type Category } from '@/types/category';
import { type Transaction } from '@/types/transaction';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { StepCategorizeTransactions } from './step-categorize-transactions';

const { update } = vi.hoisted(() => ({ update: vi.fn() }));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { update },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

const { toast } = vi.hoisted(() => ({
    toast: { success: vi.fn(), error: vi.fn(), dismiss: vi.fn() },
}));

vi.mock('sonner', () => ({ toast }));

// The dialogs and the categorizer itself belong to the transactions page and
// are tested there; this suite is about the step's own gate.
vi.mock('@/components/automation-rules/automation-rules-dialog', () => ({
    AutomationRulesDialog: () => null,
}));

vi.mock('@/components/automation-rules/post-save-apply-rule-prompt', () => ({
    PostSaveApplyRulePrompt: () => null,
}));

vi.mock('@/components/transactions/categorizer-card', () => ({
    CategorizerCard: ({
        transaction,
    }: {
        transaction: { decryptedDescription?: string } | undefined;
    }) => <div data-testid="card">{transaction?.decryptedDescription}</div>,
}));

vi.mock('@/components/transactions/categorizer-command', () => ({
    CategorizerCommand: ({
        onCategorySelect,
    }: {
        onCategorySelect: (category: { id: string; name: string }) => void;
    }) => (
        <button
            type="button"
            onClick={() => onCategorySelect({ id: 'category-1', name: 'Food' })}
        >
            Food
        </button>
    ),
}));

const transactions = Array.from({ length: 5 }, (_, index) => ({
    id: `transaction-${index}`,
    user_id: 'user-1',
    account_id: 'account-1',
    category_id: null,
    description: `MOVEMENT ${index}`,
    description_iv: null,
    transaction_date: `2026-03-0${index + 1}`,
    amount: -1000 - index,
    currency_code: 'EUR',
    notes: null,
    notes_iv: null,
    source: 'imported',
    created_at: '2026-03-01T00:00:00Z',
    updated_at: '2026-03-01T00:00:00Z',
})) as unknown as Transaction[];

const categories = [
    { id: 'category-1', name: 'Food' },
] as unknown as Category[];

function renderStep(onComplete = vi.fn()) {
    render(
        <StepCategorizeTransactions
            categories={categories}
            accounts={[]}
            banks={[]}
            transactions={transactions}
            onComplete={onComplete}
        />,
    );

    return onComplete;
}

/** One trip through the animation, which is what gates the next click. */
async function settle() {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(1200);
    });
}

async function file() {
    fireEvent.click(screen.getByRole('button', { name: 'Food' }));
    await settle();
}

async function skip() {
    fireEvent.click(screen.getByRole('button', { name: /Skip/ }));
    await settle();
}

const continueButton = () => screen.getByRole('button', { name: 'Continue' });

describe('StepCategorizeTransactions gate', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        update.mockResolvedValue(undefined);
        toast.success.mockReset();
        toast.dismiss.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
        update.mockReset();
    });

    it('opens only once the minimum has really been categorized', async () => {
        renderStep();
        await act(async () => {});

        expect(continueButton()).toBeDisabled();

        for (let filed = 0; filed < 4; filed++) {
            await file();
            expect(continueButton()).toBeDisabled();
        }

        await file();
        expect(continueButton()).toBeEnabled();
    });

    // The hole this PR closes: skipping advanced the index, so running the
    // queue out used to count as finishing the step with nothing categorized.
    it('stays shut when every movement is skipped instead', async () => {
        renderStep();
        await act(async () => {});

        for (let skipped = 0; skipped < 6; skipped++) {
            await skip();
        }

        expect(continueButton()).toBeDisabled();
    });

    // ...and the promise the footer makes: a skip brings another one round
    // rather than leaving the step with an empty screen.
    it('brings another movement round when one is skipped', async () => {
        renderStep();
        await act(async () => {});

        const first = screen.getByTestId('card').textContent;

        await skip();

        const second = screen.getByTestId('card').textContent;
        expect(second).not.toBe('');
        expect(second).not.toBe(first);
    });

    // Filing five in a row used to stack five twelve-second toasts, and three
    // of them were still on screen two steps later, over the close screen and
    // then the paywall. One id, a short life, and gone with the step.
    it('keeps its confirmations to one, short-lived and no longer than the step', async () => {
        renderStep();
        await act(async () => {});

        await file();
        await file();

        const ids = toast.success.mock.calls.map(([, options]) => options.id);
        expect(ids).toEqual([ids[0], ids[0]]);
        expect(ids[0]).toBeTruthy();
        expect(toast.success.mock.calls[0][1].duration).toBeLessThan(12000);

        cleanup();

        expect(toast.dismiss).toHaveBeenCalledWith(ids[0]);
    });
});
