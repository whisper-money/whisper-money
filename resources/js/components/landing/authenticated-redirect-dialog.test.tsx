import { dashboard } from '@/routes';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AuthenticatedRedirectDialog from './authenticated-redirect-dialog';

const mocks = vi.hoisted(() => ({
    routerVisit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: mocks.routerVisit,
    },
}));

describe('AuthenticatedRedirectDialog', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        mocks.routerVisit.mockClear();
    });

    afterEach(() => {
        vi.clearAllTimers();
        vi.useRealTimers();
    });

    it('redirects to the dashboard after three seconds', () => {
        render(<AuthenticatedRedirectDialog open />);

        expect(
            screen.getByText('You are being redirected in 3 seconds.'),
        ).not.toBeNull();
        expect(
            screen.getByRole('progressbar').getAttribute('aria-valuemax'),
        ).toBe('100');

        act(() => {
            vi.advanceTimersByTime(2999);
        });

        expect(mocks.routerVisit).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(1);
        });

        expect(mocks.routerVisit).toHaveBeenCalledWith(dashboard());
    });

    it('fills the progress bar while waiting to redirect', () => {
        render(<AuthenticatedRedirectDialog open />);

        expect(
            screen.getByRole('progressbar').getAttribute('aria-valuenow'),
        ).toBe('0');

        act(() => {
            vi.advanceTimersByTime(1500);
        });

        expect(
            screen.getByRole('progressbar').getAttribute('aria-valuenow'),
        ).toBe('50');
    });

    it('cancels the redirect and closes the modal', () => {
        render(<AuthenticatedRedirectDialog open />);

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        act(() => {
            vi.advanceTimersByTime(3000);
        });

        expect(mocks.routerVisit).not.toHaveBeenCalled();
        expect(
            screen.queryByText('You are being redirected in 3 seconds.'),
        ).toBeNull();
    });
});
