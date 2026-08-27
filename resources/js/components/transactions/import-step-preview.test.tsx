import { type ParsedTransaction } from '@/types/import';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ImportStepPreview } from './import-step-preview';

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode: vi.fn(),
        setPrivacyMode: vi.fn(),
    }),
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

// The step fetches the account's latest transactions on mount. That band is not
// what these tests are about, so the request is left hanging.
vi.mock('axios', () => ({
    default: { get: () => new Promise(() => {}) },
}));

function transaction(
    overrides: Partial<ParsedTransaction> = {},
): ParsedTransaction {
    return {
        transaction_date: '2026-03-04',
        description: 'Coffee',
        amount: -450,
        selected: true,
        ...overrides,
    };
}

function renderStep(
    transactions: ParsedTransaction[],
    props: Partial<Parameters<typeof ImportStepPreview>[0]> = {},
) {
    return render(
        <ImportStepPreview
            transactions={transactions}
            currencyCode="EUR"
            accountId="account-1"
            onConfirm={vi.fn()}
            onBack={vi.fn()}
            onSelectionChange={vi.fn()}
            onSelectAll={vi.fn()}
            isImporting={false}
            {...props}
        />,
    );
}

describe('ImportStepPreview', () => {
    it('keeps duplicates out of the main table and counts them in their own band', () => {
        renderStep([
            transaction({ description: 'Coffee' }),
            transaction({
                description: 'Rent',
                amount: -90000,
                isDuplicate: true,
                selected: false,
            }),
        ]);

        expect(screen.getByText('Coffee')).not.toBeNull();
        expect(
            screen.getByText('1 row is already in this account'),
        ).not.toBeNull();
        expect(screen.getByText("Won't be imported")).not.toBeNull();

        // Only the importable row is tickable, and the duplicate is folded away.
        expect(screen.queryByText('Rent')).toBeNull();
        expect(
            screen.getAllByRole('checkbox', { name: /Select/ }),
        ).toHaveLength(2); // select-all plus the one importable row
    });

    it('leaves the duplicates band out when nothing is a duplicate', () => {
        renderStep([transaction()]);

        expect(screen.queryByText(/already in this account/)).toBeNull();
    });

    it('asks for all the selectable rows when the header checkbox is ticked', () => {
        const onSelectAll = vi.fn();

        renderStep(
            [
                transaction({ selected: false }),
                transaction({ description: 'Rent', isDuplicate: true }),
            ],
            { onSelectAll },
        );

        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Select all transactions' }),
        );

        expect(onSelectAll).toHaveBeenCalledWith(true);
    });

    it('reports selection against the full row index, duplicates included', () => {
        const onSelectionChange = vi.fn();

        renderStep(
            [
                transaction({ description: 'Rent', isDuplicate: true }),
                transaction({ description: 'Coffee' }),
            ],
            { onSelectionChange },
        );

        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Select Coffee' }),
        );

        expect(onSelectionChange).toHaveBeenCalledWith(1, false);
    });

    it('sums the net of the selected rows and shows their date range', () => {
        renderStep([
            transaction({ transaction_date: '2026-03-04', amount: -450 }),
            transaction({
                transaction_date: '2026-03-20',
                description: 'Salary',
                amount: 250000,
            }),
        ]);

        expect(screen.getByText('Net')).not.toBeNull();
        expect(screen.getByText('€2,495.50')).not.toBeNull();
        expect(screen.getByText('Mar 4, 2026 → Mar 20, 2026')).not.toBeNull();
        expect(screen.getByText('2 of 2 selected')).not.toBeNull();
    });

    it('collapses the date range to a single date when every row shares it', () => {
        renderStep([transaction(), transaction({ description: 'Bread' })]);

        // Both rows print it, and so does the summary strip — but only once.
        expect(screen.getAllByText('Mar 4, 2026')).toHaveLength(3);
        expect(screen.queryByText(/→/)).toBeNull();
    });

    it('drops the net when the selected rows carry more than one currency', () => {
        renderStep([
            transaction({ currency_code: 'EUR' }),
            transaction({ description: 'Hotel', currency_code: 'USD' }),
        ]);

        expect(screen.queryByText('Net')).toBeNull();
        expect(screen.getByText('2 of 2 selected')).not.toBeNull();
    });

    it('counts only the importable rows in the footer and the import button', () => {
        renderStep([
            transaction(),
            transaction({ description: 'Bread', selected: false }),
            transaction({ description: 'Rent', isDuplicate: true }),
        ]);

        expect(screen.getByText('1 of 2 rows ready')).not.toBeNull();
        expect(
            screen.getByRole('button', { name: 'Import 1 transaction' }),
        ).not.toBeNull();
    });

    it('has nothing to import when every row is already in the account', () => {
        renderStep([
            transaction({ isDuplicate: true, selected: false }),
            transaction({
                description: 'Rent',
                isDuplicate: true,
                selected: false,
            }),
        ]);

        expect(screen.getByText('No valid transactions found')).not.toBeNull();
        expect(
            screen.getByRole('button', { name: 'Import 0 transactions' }),
        ).toHaveProperty('disabled', true);
        expect(
            screen.getByText('2 rows are already in this account'),
        ).not.toBeNull();
        expect(
            screen.getByRole('checkbox', { name: 'Select all transactions' }),
        ).toHaveProperty('disabled', true);
    });
});
