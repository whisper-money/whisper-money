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
// the count it reports back when an import finishes, and the close.
vi.mock('@/components/transactions/import-transactions-drawer', () => ({
    ImportTransactionsDrawer: ({
        onImportComplete,
        onOpenChange,
    }: {
        onImportComplete?: (importedCount: number) => void;
        onOpenChange: (open: boolean) => void;
    }) => (
        <>
            <button type="button" onClick={() => onImportComplete?.(7)}>
                finish import
            </button>
            <button type="button" onClick={() => onImportComplete?.(0)}>
                fail import
            </button>
            <button type="button" onClick={() => onOpenChange(false)}>
                close drawer
            </button>
        </>
    ),
}));

function openDrawer() {
    fireEvent.click(screen.getByText('Import Transactions'));
}

describe('StepImportTransactions analytics', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    // The count comes from the import itself, which is the only thing that
    // separates a real import from an abandoned one.
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

        openDrawer();

        expect(captureEvent).not.toHaveBeenCalled();
    });
});

describe('StepImportTransactions step completion', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('moves on once an import has brought something in', () => {
        const onComplete = vi.fn();
        render(
            <StepImportTransactions
                account={undefined}
                onComplete={onComplete}
            />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('finish import'));
        fireEvent.click(screen.getByText('close drawer'));

        expect(onComplete).toHaveBeenCalled();
    });

    // The drawer closes itself on a clean import, before reporting the count.
    it('moves on when the count arrives after the close', () => {
        const onComplete = vi.fn();
        render(
            <StepImportTransactions
                account={undefined}
                onComplete={onComplete}
            />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('close drawer'));
        fireEvent.click(screen.getByText('finish import'));

        expect(onComplete).toHaveBeenCalled();
    });

    // Closing with the X used to count as an import and advance the wizard.
    it('stays put when the drawer is closed without importing anything', () => {
        const onComplete = vi.fn();
        render(
            <StepImportTransactions
                account={undefined}
                onComplete={onComplete}
            />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('close drawer'));

        expect(onComplete).not.toHaveBeenCalled();
    });

    it('stays put when every row of the import failed', () => {
        const onComplete = vi.fn();
        render(
            <StepImportTransactions
                account={undefined}
                onComplete={onComplete}
            />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('fail import'));
        fireEvent.click(screen.getByText('close drawer'));

        expect(onComplete).not.toHaveBeenCalled();
    });

    // A partial import keeps the drawer open for a retry, so the step has to
    // wait there rather than unmount the drawer mid-retry.
    it('waits while a partial import is still open', () => {
        const onComplete = vi.fn();
        render(
            <StepImportTransactions
                account={undefined}
                onComplete={onComplete}
            />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('finish import'));

        expect(onComplete).not.toHaveBeenCalled();
    });
});

// Without a CSV to hand, "Import Transactions" was the only action on the
// screen, so an account created by hand left the user stuck on this step.
describe('StepImportTransactions way out', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('offers no way out until the import has been seen', () => {
        render(
            <StepImportTransactions account={undefined} onComplete={vi.fn()} />,
        );

        expect(screen.queryByText('Skip for now')).not.toBeInTheDocument();
        expect(screen.queryByText('Continue')).not.toBeInTheDocument();
    });

    it('offers a way out once the drawer is closed with nothing imported', () => {
        render(
            <StepImportTransactions account={undefined} onComplete={vi.fn()} />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('close drawer'));

        expect(screen.getByText('Skip for now')).toBeInTheDocument();
    });

    it('leaves the step when the way out is taken', () => {
        const onComplete = vi.fn();
        render(
            <StepImportTransactions
                account={undefined}
                onComplete={onComplete}
            />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('close drawer'));
        fireEvent.click(screen.getByText('Skip for now'));

        expect(onComplete).toHaveBeenCalled();
    });

    // The way out is a button the user has to press: appearing is not
    // advancing.
    it('does not advance on its own once the way out is offered', () => {
        const onComplete = vi.fn();
        render(
            <StepImportTransactions
                account={undefined}
                onComplete={onComplete}
            />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('close drawer'));

        expect(screen.getByText('Skip for now')).toBeInTheDocument();
        expect(onComplete).not.toHaveBeenCalled();
    });

    // An import that brought something in is a continuation, not a skip.
    it('reads as a continuation once something has been imported', () => {
        render(
            <StepImportTransactions account={undefined} onComplete={vi.fn()} />,
        );

        openDrawer();
        fireEvent.click(screen.getByText('finish import'));

        expect(screen.getByText('Continue')).toBeInTheDocument();
        expect(screen.queryByText('Skip for now')).not.toBeInTheDocument();
    });
});
