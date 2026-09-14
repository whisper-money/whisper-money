import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepImportTransactions } from './step-import-transactions';

const { captureEvent } = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({
        props: {
            accounts: [{ id: 'account-1' }],
            categories: [],
            banks: [],
            automationRules: [],
        },
    }),
}));

// The real drawer is a multi-step CSV parser; all this step needs from it is
// the count it reports back when an import finishes.
vi.mock('@/components/transactions/import-transactions-drawer', () => ({
    ImportTransactionsDrawer: ({
        onImportComplete,
    }: {
        onImportComplete?: (importedCount: number) => void;
    }) => (
        <button type="button" onClick={() => onImportComplete?.(7)}>
            finish import
        </button>
    ),
}));

describe('StepImportTransactions analytics', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    // The step moves on whether or not anything was imported, so the count is
    // the only thing separating a real import from an abandoned one.
    it('reports how many transactions the import brought in', () => {
        render(
            <StepImportTransactions account={undefined} onComplete={vi.fn()} />,
        );

        fireEvent.click(screen.getByText('finish import'));

        expect(captureEvent).toHaveBeenCalledOnce();
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_import_completed',
            { transactions_imported: 7 },
        );
    });

    it('reports nothing while the drawer is merely open', () => {
        render(
            <StepImportTransactions account={undefined} onComplete={vi.fn()} />,
        );

        fireEvent.click(screen.getByText('Import Transactions'));

        expect(captureEvent).not.toHaveBeenCalled();
    });
});
