import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepMapAccounts, type PendingMapping } from './step-map-accounts';

const { post, captureEvent } = vi.hoisted(() => ({
    post: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('sonner', () => ({ toast: { error: vi.fn() } }));
vi.mock('@inertiajs/react', () => ({ router: { post } }));
vi.mock('@/utils/i18n', () => ({
    __: (key: string, replacements?: Record<string, string | number>) =>
        Object.entries(replacements ?? {}).reduce(
            (text, [name, value]) => text.replace(`:${name}`, String(value)),
            key,
        ),
}));

const pending: PendingMapping = {
    connection_id: 'connection-1',
    bank_name: 'BBVA',
    bank_logo: null,
    accounts: [
        {
            uid: 'ext-1',
            name: 'Cuenta Nómina',
            iban: 'ES1234567890123456784417',
            currency: 'EUR',
        },
        {
            uid: 'ext-2',
            name: 'Cuenta Comunidad',
            iban: 'ES1234567890123456780093',
            currency: 'EUR',
        },
    ],
};

describe('StepMapAccounts', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('offers every account the bank returned, all in by default', () => {
        render(<StepMapAccounts pending={pending} />);

        expect(screen.getByText('BBVA gave us 2 accounts')).toBeInTheDocument();
        expect(screen.getByText('Cuenta Nómina')).toBeInTheDocument();
        // The IBAN is trimmed to the only part worth showing on a phone.
        expect(screen.getByText('•••• 4417 · EUR')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Track these 2' }),
        ).toBeInTheDocument();
    });

    // The point of the screen: an account left out is skipped rather than
    // created, which is what keeps a joint account out of the first picture.
    it('skips the accounts the user unticks', () => {
        render(<StepMapAccounts pending={pending} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Cuenta Comunidad/ }),
        );

        expect(
            screen.getByRole('button', { name: 'Track this one' }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Track this one' }));

        expect(post).toHaveBeenCalledWith(
            '/open-banking/connections/connection-1/map-accounts',
            {
                mappings: [
                    {
                        bank_account_uid: 'ext-1',
                        action: 'create',
                        existing_account_id: null,
                    },
                    {
                        bank_account_uid: 'ext-2',
                        action: 'skip',
                        existing_account_id: null,
                    },
                ],
            },
            expect.anything(),
        );
    });

    // Creating nothing would leave a connection with no accounts behind it,
    // which is a worse outcome than not connecting at all.
    it('will not submit with nothing selected', () => {
        render(<StepMapAccounts pending={pending} />);

        fireEvent.click(screen.getByRole('button', { name: /Cuenta Nómina/ }));
        fireEvent.click(
            screen.getByRole('button', { name: /Cuenta Comunidad/ }),
        );

        expect(
            screen.getByRole('button', { name: 'Track these 0' }),
        ).toBeDisabled();
        expect(post).not.toHaveBeenCalled();
    });

    // How many of the bank's accounts a user actually wants cannot be read back
    // afterwards: the skipped ones leave no trace.
    it('reports what was offered against what was kept', () => {
        render(<StepMapAccounts pending={pending} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Cuenta Comunidad/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Track this one' }));

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_bank_accounts_mapped',
            { offered: 2, kept: 1 },
        );
    });
});
