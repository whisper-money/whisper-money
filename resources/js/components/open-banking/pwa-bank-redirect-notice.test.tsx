import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    PwaBankRedirectNotice,
    usePwaReconnectGate,
} from './pwa-bank-redirect-notice';

const mocks = vi.hoisted(() => ({
    installed: false,
    platform: 'android' as 'android' | 'ios' | null,
    leavePage: vi.fn(),
    captureEvent: vi.fn(),
    toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock('@/lib/pwa', () => ({
    isInstalledPwa: () => mocks.installed,
    detectMobilePlatform: () => mocks.platform,
}));
vi.mock('@/lib/leave-page', () => ({ leavePage: mocks.leavePage }));
vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('sonner', () => ({ toast: mocks.toast }));
vi.mock('@/utils/i18n', () => ({
    __: (key: string, replacements?: Record<string, string>) =>
        Object.entries(replacements ?? {}).reduce(
            (text, [name, value]) => text.replace(`:${name}`, value),
            key,
        ),
}));

const reconnectPath = '/open-banking/connections/c-1/reconnect';

describe('PwaBankRedirectNotice', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.platform = 'android';
    });

    it('opens the page in Chrome on Android', () => {
        render(
            <PwaBankRedirectNotice
                browserUrl={reconnectPath}
                flow="reconnect"
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Open in browser/ }),
        );

        const host = window.location.host;
        expect(mocks.leavePage).toHaveBeenCalledWith(
            expect.stringMatching(
                new RegExp(
                    `^intent://${host}${reconnectPath}#Intent;scheme=https;package=com\\.android\\.chrome;`,
                ),
            ),
        );
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'pwa_bank_redirect_choice',
            { flow: 'reconnect', choice: 'open_in_browser' },
        );
    });

    it('copies the link for Safari on iOS', async () => {
        mocks.platform = 'ios';
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText },
        });

        render(
            <PwaBankRedirectNotice browserUrl={reconnectPath} flow="connect" />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Copy link/ }));

        await waitFor(() =>
            expect(mocks.toast.success).toHaveBeenCalledWith(
                'Link copied. Paste it in Safari.',
            ),
        );
        expect(writeText).toHaveBeenCalledWith(
            `${window.location.origin}${reconnectPath}`,
        );
        expect(mocks.leavePage).not.toHaveBeenCalled();
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'pwa_bank_redirect_choice',
            { flow: 'connect', choice: 'copy_link' },
        );
    });
});

function GateHarness({ proceed }: { proceed: () => void }) {
    const { requestReconnect, dialog } = usePwaReconnectGate();

    return (
        <>
            <button
                onClick={() =>
                    requestReconnect({
                        bankName: 'BBVA',
                        browserUrl: reconnectPath,
                        proceed,
                    })
                }
            >
                Reconnect
            </button>
            {dialog}
        </>
    );
}

describe('usePwaReconnectGate', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.installed = false;
    });

    it('goes straight to the bank in a browser tab', () => {
        const proceed = vi.fn();
        render(<GateHarness proceed={proceed} />);

        fireEvent.click(screen.getByRole('button', { name: 'Reconnect' }));

        expect(proceed).toHaveBeenCalledOnce();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('asks first in the installed app', () => {
        mocks.installed = true;
        const proceed = vi.fn();
        render(<GateHarness proceed={proceed} />);

        fireEvent.click(screen.getByRole('button', { name: 'Reconnect' }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(proceed).not.toHaveBeenCalled();
        expect(screen.getByText('Reconnect BBVA')).toBeInTheDocument();
        expect(screen.getByText('Better from the browser')).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Reconnect here anyway' }),
        );

        expect(proceed).toHaveBeenCalledOnce();
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'pwa_bank_redirect_choice',
            { flow: 'reconnect', choice: 'here_anyway' },
        );
    });

    it('does nothing when the user cancels', () => {
        mocks.installed = true;
        const proceed = vi.fn();
        render(<GateHarness proceed={proceed} />);

        fireEvent.click(screen.getByRole('button', { name: 'Reconnect' }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(proceed).not.toHaveBeenCalled();
        expect(mocks.captureEvent).not.toHaveBeenCalled();
    });
});
