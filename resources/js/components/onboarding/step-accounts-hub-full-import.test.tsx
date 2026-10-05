import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepAccountsHub } from './step-accounts-hub';

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

const features = vi.hoisted(() => ({ fullImport: true }));
const reload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', async () => {
    const { pageProps } = await import('@/lib/onboarding-page-props');

    return {
        usePage: () => ({ props: { ...pageProps, features } }),
        router: { reload },
    };
});

vi.mock('@/components/full-import/full-import-wizard', () => ({
    FullImportWizard: ({
        onClose,
        onFinished,
    }: {
        onClose: () => void;
        onFinished: () => void;
    }) => (
        <div data-testid="full-import-wizard">
            <button onClick={onClose}>close wizard</button>
            <button onClick={onFinished}>finish wizard</button>
        </div>
    ),
}));

function renderHub(props: Partial<Parameters<typeof StepAccountsHub>[0]> = {}) {
    render(
        <StepAccountsHub
            banks={[]}
            isFirstAccount
            onAccountCreated={vi.fn()}
            {...props}
        />,
    );
}

describe('StepAccountsHub full import', () => {
    afterEach(() => {
        features.fullImport = true;
        reload.mockClear();
    });

    it('offers the import from another app and opens it inline', () => {
        renderHub();

        fireEvent.click(screen.getByText('Coming from another app?'));

        expect(screen.getByTestId('full-import-wizard')).toBeInTheDocument();
    });

    it('comes back to the hub when the wizard closes or finishes', () => {
        renderHub();

        fireEvent.click(screen.getByText('Coming from another app?'));
        fireEvent.click(screen.getByText('close wizard'));

        expect(screen.getByText('Connect a bank')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Coming from another app?'));
        fireEvent.click(screen.getByText('finish wizard'));

        expect(reload).toHaveBeenCalledWith({ only: ['accounts'] });
        expect(screen.getByText('Connect a bank')).toBeInTheDocument();
    });

    it('hides the row from a user who may not import', () => {
        features.fullImport = false;
        renderHub();

        expect(
            screen.queryByText('Coming from another app?'),
        ).not.toBeInTheDocument();
    });
});
