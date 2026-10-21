import { detectAccounts } from '@/lib/full-import-plan';
import { type AccountPlanEntry, type NormalizedRow } from '@/types/full-import';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepAccounts } from './step-accounts';

vi.mock('@/components/accounts/bank-combobox', () => ({
    BankCombobox: () => null,
}));

function row(overrides: Partial<NormalizedRow>): NormalizedRow {
    return {
        rowNumber: 2,
        kind: 'movement',
        date: '2026-09-01',
        amount: -10,
        description: 'Movement',
        notes: null,
        accountKey: 'live',
        accountName: 'Live',
        accountBank: '',
        categoryPath: [],
        currency: 'EUR',
        balance: null,
        iban: null,
        externalId: null,
        ignored: false,
        ...overrides,
    };
}

const fileAccounts = detectAccounts([
    row({ accountKey: 'live', accountName: 'Live', balance: 90 }),
    row({
        accountKey: 'old',
        accountName: 'Old savings',
        date: '2024-05-01',
        balance: 0,
    }),
]);

function entry(overrides: Partial<AccountPlanEntry>): AccountPlanEntry {
    return {
        action: 'create',
        name: 'Account',
        type: 'checking',
        currencyCode: 'EUR',
        bank: null,
        newBankName: null,
        targetAccountId: null,
        mergeIntoKey: null,
        archive: false,
        ...overrides,
    };
}

function renderStep(inactiveKeys: string[], onChange = vi.fn()) {
    render(
        <StepAccounts
            eyebrow="Step 3"
            fileAccounts={fileAccounts}
            plan={{
                live: entry({ name: 'Live' }),
                old: entry({ name: 'Old savings', archive: true }),
            }}
            inactiveAccounts={fileAccounts.filter((account) =>
                inactiveKeys.includes(account.key),
            )}
            onChange={onChange}
            mode="add"
            accounts={[]}
            mappableAccountIds={[]}
            currencies={[{ code: 'EUR', name: 'Euro' }]}
            sourceLabel="Banktrack"
            banksVersion="0"
            locale="en-US"
            footer={null}
        />,
    );

    return onChange;
}

describe('StepAccounts', () => {
    it('suggests archiving the inactive accounts, pre-ticked', () => {
        const onChange = renderStep(['old']);

        expect(
            screen.getByText(
                '1 account looks inactive — archive it after import',
            ),
        ).toBeInTheDocument();

        const toggles = screen.getAllByRole('checkbox', {
            name: 'Archive after import',
        });

        expect(toggles).toHaveLength(1);
        expect(toggles[0]).toBeChecked();

        fireEvent.click(toggles[0]);

        expect(onChange).toHaveBeenCalledWith(
            'old',
            expect.objectContaining({ archive: false }),
        );
    });

    it('shows nothing new when every account is in use', () => {
        renderStep([]);

        expect(screen.queryByText(/looks? inactive/)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('checkbox', { name: 'Archive after import' }),
        ).not.toBeInTheDocument();
    });
});
