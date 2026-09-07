import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SyncedBalanceNotice } from './synced-balance-notice';

const manualAccount = {
    id: 'account-1',
    name: 'Checking',
    name_iv: null,
    encrypted: false,
    bank: null,
    type: 'checking' as const,
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

describe('SyncedBalanceNotice', () => {
    it('warns that the sync owns today and later on a connected account', () => {
        render(
            <SyncedBalanceNotice
                account={{
                    ...manualAccount,
                    banking_connection_id: 'connection-1',
                }}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            /Every sync overwrites today's figure/,
        );
    });

    it('says nothing on a manual account', () => {
        render(<SyncedBalanceNotice account={manualAccount} />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('says nothing without an account to judge', () => {
        render(<SyncedBalanceNotice />);

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
});
