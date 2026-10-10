import {
    PrivacyModeProvider,
    PrivacyRevealScope,
    usePrivacyMode,
} from '@/contexts/privacy-mode-context';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';
import { PrivacyRevealButton } from './privacy-reveal-button';

function MaskState({ testId }: { testId: string }) {
    const { isPrivacyModeEnabled } = usePrivacyMode();

    return <span data-testid={testId}>{String(isPrivacyModeEnabled)}</span>;
}

function GlobalToggle() {
    const { togglePrivacyMode } = usePrivacyMode();

    return (
        <button type="button" onClick={togglePrivacyMode}>
            global
        </button>
    );
}

function Block({ name }: { name: string }) {
    return (
        <PrivacyRevealScope>
            <section aria-label={name}>
                <PrivacyRevealButton />
                <MaskState testId={`${name}-masked`} />
            </section>
        </PrivacyRevealScope>
    );
}

function renderDashboard() {
    return render(
        <PrivacyModeProvider>
            <GlobalToggle />
            <MaskState testId="outside-masked" />
            <Block name="cash" />
            <Block name="savings" />
        </PrivacyModeProvider>,
    );
}

function revealButtonOf(block: string) {
    return screen
        .getByRole('region', { name: block })
        .querySelector('button') as HTMLButtonElement;
}

describe('PrivacyRevealButton', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('is not rendered while privacy mode is off', () => {
        renderDashboard();

        expect(
            screen.queryByRole('button', {
                name: 'Show amounts in this block',
            }),
        ).toBeNull();
        expect(screen.getByTestId('cash-masked').textContent).toBe('false');
    });

    it('reveals only its own block while privacy mode is on', () => {
        localStorage.setItem('privacy-mode-enabled', 'true');
        renderDashboard();

        const button = revealButtonOf('cash');
        expect(button.getAttribute('aria-label')).toBe(
            'Show amounts in this block',
        );
        expect(button.getAttribute('aria-pressed')).toBe('false');

        fireEvent.click(button);

        expect(screen.getByTestId('cash-masked').textContent).toBe('false');
        expect(screen.getByTestId('savings-masked').textContent).toBe('true');
        expect(screen.getByTestId('outside-masked').textContent).toBe('true');
        expect(button.getAttribute('aria-label')).toBe(
            'Hide amounts in this block',
        );
        expect(button.getAttribute('aria-pressed')).toBe('true');

        fireEvent.click(button);

        expect(screen.getByTestId('cash-masked').textContent).toBe('true');
    });

    it('drops every reveal when global privacy mode is toggled', () => {
        localStorage.setItem('privacy-mode-enabled', 'true');
        renderDashboard();

        fireEvent.click(revealButtonOf('cash'));
        expect(screen.getByTestId('cash-masked').textContent).toBe('false');

        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'global' }));
        });
        act(() => {
            fireEvent.click(screen.getByRole('button', { name: 'global' }));
        });

        expect(screen.getByTestId('cash-masked').textContent).toBe('true');
        expect(revealButtonOf('cash').getAttribute('aria-pressed')).toBe(
            'false',
        );
    });

    it('is not rendered outside a reveal scope', () => {
        localStorage.setItem('privacy-mode-enabled', 'true');
        render(
            <PrivacyModeProvider>
                <PrivacyRevealButton />
            </PrivacyModeProvider>,
        );

        expect(screen.queryByRole('button')).toBeNull();
    });
});
