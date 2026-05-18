import type { Account } from '@/types/account';
import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ImportStepAccount } from './import-step-account';

const account: Account = {
    id: 'account-1',
    name: 'Checking',
    name_iv: null,
    encrypted: false,
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

    it('auto-selects a single account when enabled for onboarding', async () => {
        const onAutoSelect = vi.fn();
        const onAccountSelect = vi.fn();
        const onNext = vi.fn();

        render(
            <ImportStepAccount
                accounts={[account]}
                selectedAccountId={null}
                onAccountSelect={onAccountSelect}
                onAutoSelect={onAutoSelect}
                onNext={onNext}
                autoSelectSingleAccount
            />,
        );

        await waitFor(() => {
            expect(onAutoSelect).toHaveBeenCalledWith(account.id);
            expect(onNext).toHaveBeenCalled();
        });
        expect(onAccountSelect).not.toHaveBeenCalled();
    });
});
