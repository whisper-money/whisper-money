import type { Account } from '@/types/account';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ArchiveAccountDialog } from './archive-account-dialog';

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        action,
    }: {
        children: (args: { processing: boolean }) => ReactNode;
        action?: string;
    }) => <form action={action}>{children({ processing: false })}</form>,
}));

vi.mock('@/actions/App/Http/Controllers/AccountController', () => ({
    updateArchived: {
        form: {
            patch: (id: string) => ({
                action: `/accounts/${id}/archived`,
                method: 'patch',
            }),
        },
    },
}));

const account = { id: 'acc-1', name: 'Old savings' } as Account;

describe('ArchiveAccountDialog', () => {
    it('names the account and spells out what archiving does', () => {
        render(
            <ArchiveAccountDialog
                account={account}
                open={true}
                onOpenChange={() => {}}
            />,
        );

        expect(
            screen.getByText(/Archiving Old savings puts it away/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/disappears from the dashboard/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /stops counting towards your net worth, your spending and your category totals from today/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/stay in your history and stay editable/),
        ).toBeInTheDocument();
        expect(screen.getByText(/bring it back any time/)).toBeInTheDocument();
    });

    it('warns a connected account will be disconnected', () => {
        render(
            <ArchiveAccountDialog
                account={
                    { ...account, banking_connection_id: 'conn-1' } as Account
                }
                open={true}
                onOpenChange={() => {}}
            />,
        );

        expect(
            screen.getByText(/disconnected from the bank/),
        ).toBeInTheDocument();
    });

    it('does not mention the bank for a manual account', () => {
        render(
            <ArchiveAccountDialog
                account={account}
                open={true}
                onOpenChange={() => {}}
            />,
        );

        expect(
            screen.queryByText(/disconnected from the bank/),
        ).not.toBeInTheDocument();
    });

    it('submits archived=1 to the account it was given', () => {
        render(
            <ArchiveAccountDialog
                account={account}
                open={true}
                onOpenChange={() => {}}
            />,
        );

        // The dialog renders through a portal, so it is not under `container`.
        expect(document.querySelector('form')).toHaveAttribute(
            'action',
            '/accounts/acc-1/archived',
        );
        expect(document.querySelector('input[name="archived"]')).toHaveValue(
            '1',
        );
    });

    it('renders nothing while closed', () => {
        render(
            <ArchiveAccountDialog
                account={account}
                open={false}
                onOpenChange={() => {}}
            />,
        );

        expect(screen.queryByText(/puts it away/)).not.toBeInTheDocument();
    });
});
