import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AppToaster } from './app-toaster';

vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {} },
}));

// Sonner only mounts its container once there is something to show, so the
// assertions are on the props the region is given rather than on the DOM.
vi.mock('sonner', () => ({
    Toaster: (props: Record<string, unknown>) => (
        <div
            data-testid="toaster"
            data-position={String(props.position)}
            data-mobile-offset={JSON.stringify(props.mobileOffset)}
        />
    ),
}));

function renderAt(path: string) {
    window.history.replaceState({}, '', path);
    render(<AppToaster />);

    return screen.getByTestId('toaster');
}

afterEach(() => {
    window.history.replaceState({}, '', '/');
});

describe('AppToaster', () => {
    // A toast at the bottom of a step lands on the action the step pins there:
    // at 390px the network toast covered "Let's go" and the click was refused
    // as intercepted.
    it('opens toasts at the top of the onboarding wizard', () => {
        const toaster = renderAt('/onboarding?step=plan');

        expect(toaster.dataset.position).toBe('top-center');
        expect(toaster.dataset.mobileOffset).toBe(undefined);
    });

    it('leaves them at the bottom, above the mobile tab bar, everywhere else', () => {
        const toaster = renderAt('/dashboard');

        expect(toaster.dataset.position).toBe('undefined');
        expect(toaster.dataset.mobileOffset).toBe('{"bottom":"110px"}');
    });
});
