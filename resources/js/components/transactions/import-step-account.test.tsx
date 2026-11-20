import documentation from '@/routes/documentation';
import type { Account } from '@/types/account';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ImportStepAccount } from './import-step-account';

const account: Account = {
    id: 'account-1',
    name: 'Checking',
    bank: null,
    type: 'checking',
    currency_code: 'USD',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

describe('ImportStepAccount', () => {
    it('shows a single account without auto-selecting by default', () => {
        const onAccountSelect = vi.fn();
        const onNext = vi.fn();

        render(
            <ImportStepAccount
                accounts={[account]}
                selectedAccountId={null}
                onAccountSelect={onAccountSelect}
                onNext={onNext}
            />,
        );

        expect(screen.getByText('Checking')).not.toBeNull();
        expect(screen.getByRole('button', { name: 'Next' })).toHaveProperty(
            'disabled',
            true,
        );
        expect(onAccountSelect).not.toHaveBeenCalled();
        expect(onNext).not.toHaveBeenCalled();
    });

    it('links the broker guide next to the way on without an account', () => {
        render(
            <ImportStepAccount
                accounts={[account]}
                selectedAccountId={null}
                onAccountSelect={vi.fn()}
                onNext={vi.fn()}
                onImportWithoutAccount={vi.fn()}
            />,
        );

        const link = screen.getByRole('link', {
            name: 'How to get the export',
        });

        expect(link.getAttribute('href')).toBe(
            documentation.show.url('transactions/import-from-a-broker'),
        );
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener noreferrer');
    });

    it('leaves the broker guide out when there is no way on without an account', () => {
        render(
            <ImportStepAccount
                accounts={[account]}
                selectedAccountId={null}
                onAccountSelect={vi.fn()}
                onNext={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'How to get the export' }),
        ).toBeNull();
    });
});
