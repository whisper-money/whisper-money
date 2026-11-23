import { ConnectionStatusBadge } from '@/components/open-banking/connection-status-badge';
import type { BankingConnection } from '@/types/banking';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

function connection(
    overrides: Partial<BankingConnection> = {},
): BankingConnection {
    return {
        id: 'connection-1',
        provider: 'enable_banking',
        aspsp_name: 'Sabadell',
        aspsp_country: 'ES',
        status: 'active',
        valid_until: null,
        last_synced_at: '2026-01-01T00:00:00Z',
        error_message: null,
        accounts_count: 1,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        ...overrides,
    };
}

function badgeClasses(text: string): string {
    return screen.getByText(text).closest('[data-slot="badge"]')!.className;
}

describe('<ConnectionStatusBadge>', () => {
    it('colors a healthy connection with the success token', () => {
        render(<ConnectionStatusBadge connection={connection()} />);

        expect(badgeClasses('Active')).toContain('text-success');
    });

    it('colors a connection that needs the user with the warning token', () => {
        const inThreeDays = new Date(
            Date.now() + 3 * 24 * 60 * 60 * 1000,
        ).toISOString();

        render(
            <ConnectionStatusBadge
                connection={connection({ valid_until: inThreeDays })}
            />,
        );

        expect(badgeClasses('Expiring soon')).toContain('text-warning');
    });

    it('marks a setup that is still required as a warning', () => {
        render(
            <ConnectionStatusBadge
                connection={connection({ status: 'awaiting_mapping' })}
            />,
        );

        expect(badgeClasses('Setup Required')).toContain('text-warning');
    });

    it('never falls back to palette colors', () => {
        const { container } = render(
            <>
                <ConnectionStatusBadge
                    connection={connection({ last_synced_at: null })}
                />
                <ConnectionStatusBadge
                    connection={connection({ status: 'pending' })}
                />
            </>,
        );

        expect(container.innerHTML).not.toMatch(
            /-(green|red|emerald|rose|amber|yellow)-\d/,
        );
    });
});
