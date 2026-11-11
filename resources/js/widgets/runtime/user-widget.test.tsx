import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import {
    act,
    render,
    renderHook,
    screen,
    waitFor,
} from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { LOADING_MARKER_KEY } from './safe-mode';
import { SETTLE_GRACE_MS, useUserWidgetsGate } from './use-user-widgets-gate';
import { UserWidget } from './user-widget';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { visit: vi.fn() },
}));

// The stylesheet is Vite's business; the registry is the real one.
vi.mock('../../../css/widgets.css', () => ({}));

function pageProps(customDashboards: boolean) {
    page.props = {
        features: { customDashboards },
        locale: 'es-ES',
        auth: { user: { currency_code: 'EUR' } },
        accounts: [],
    };
}

/** Modules as the compiler emits them: named imports, then the component. */
const module = (body: string) =>
    `import{jsx as j}from"react/jsx-runtime";import{useWidgetData as d,useWidgetEnv as e,Amount as A}from"@whisper-money/widget-sdk";${body}`;

const RENDERS = module(
    'export default function W(){const{total}=d();const{width,currency}=e();return j("p",{children:[width," ",currency," ",j(A,{amount:total})]})}',
);

const THROWS = module(
    'export default function W(){const{rows}=d();return j("p",{children:rows.length})}',
);

function inApp(children: ReactNode) {
    return render(<PrivacyModeProvider>{children}</PrivacyModeProvider>);
}

describe('<UserWidget>', () => {
    beforeEach(() => {
        pageProps(true);
        vi.stubGlobal('matchMedia', () => ({
            matches: false,
            addEventListener: () => {},
            removeEventListener: () => {},
        }));
    });

    afterEach(() => vi.unstubAllGlobals());

    it('draws the widget with its data, size and currency', async () => {
        const onSettled = vi.fn();
        const { container } = inApp(
            <UserWidget
                js={RENDERS}
                data={{ total: 123456 }}
                size={{ width: '1/3', height: 'm' }}
                onSettled={onSettled}
            />,
        );

        expect(
            await screen.findByText(/1\/3 EUR/, undefined, { timeout: 5000 }),
        ).toBeInTheDocument();
        expect(container.querySelector('.wm-widget')).toHaveAttribute(
            'data-status',
            'ready',
        );
        expect(container.textContent).toContain('1.234,56');
        expect(onSettled).toHaveBeenCalledTimes(1);
    });

    it('keeps a widget that throws inside its own cell and reports it', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const onError = vi.fn();
        const onSettled = vi.fn();

        inApp(
            <>
                <UserWidget
                    js={THROWS}
                    data={{}}
                    size={{ width: '1/2', height: 'm' }}
                    onError={onError}
                    onSettled={onSettled}
                />
                <UserWidget
                    js={RENDERS}
                    data={{ total: 100 }}
                    size={{ width: '1/2', height: 'm' }}
                />
            </>,
        );

        expect(await screen.findByRole('alert')).toHaveTextContent(
            'This widget stopped working',
        );
        expect(await screen.findByText(/1\/2 EUR/)).toBeInTheDocument();
        expect(onError).toHaveBeenCalledWith(
            expect.objectContaining({
                phase: 'render',
                message: expect.stringContaining('length'),
            }),
        );
        expect(onSettled).toHaveBeenCalled();
    });

    it('reports a module that does not load', async () => {
        const onError = vi.fn();

        inApp(
            <UserWidget
                js='import{x}from"left-pad";export default function W(){}'
                data={{}}
                size={{ width: '1/2', height: 'm' }}
                onError={onError}
            />,
        );

        expect(await screen.findByRole('alert')).toBeInTheDocument();
        expect(onError).toHaveBeenCalledWith(
            expect.objectContaining({ phase: 'load' }),
        );
    });

    it('renders nothing with the flag off', () => {
        pageProps(false);

        const { container } = inApp(
            <UserWidget
                js={RENDERS}
                data={{}}
                size={{ width: '1/2', height: 'm' }}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});

describe('useUserWidgetsGate', () => {
    beforeEach(() => {
        pageProps(true);
        window.history.replaceState(null, '', '/dashboard');
    });

    afterEach(() => {
        vi.useRealTimers();
        localStorage.clear();
    });

    it('is off with the flag off or no user widgets', () => {
        expect(renderHook(() => useUserWidgetsGate([])).result.current).toEqual(
            { mode: 'off' },
        );

        pageProps(false);

        expect(
            renderHook(() => useUserWidgetsGate(['a'])).result.current,
        ).toEqual({ mode: 'off' });
    });

    it('marks the load before the widgets mount and clears it once they drew', () => {
        vi.useFakeTimers();
        const { result } = renderHook(() => useUserWidgetsGate(['a', 'b']));

        expect(result.current.mode).toBe('run');
        expect(localStorage.getItem(LOADING_MARKER_KEY)).not.toBeNull();

        const gate = result.current as { settle: (id: string) => void };
        act(() => gate.settle('a'));
        act(() => vi.advanceTimersByTime(SETTLE_GRACE_MS));
        expect(localStorage.getItem(LOADING_MARKER_KEY)).not.toBeNull();

        act(() => gate.settle('b'));
        act(() => vi.advanceTimersByTime(SETTLE_GRACE_MS));
        expect(localStorage.getItem(LOADING_MARKER_KEY)).toBeNull();
    });

    it('opens in safe mode when the last load never finished', () => {
        localStorage.setItem(LOADING_MARKER_KEY, '2026-11-11T10:00:00Z');

        const { result } = renderHook(() => useUserWidgetsGate(['a']));

        expect(result.current).toMatchObject({
            mode: 'safe',
            reason: 'unfinished',
        });

        act(() => (result.current as { exit: () => void }).exit());

        expect(result.current.mode).toBe('run');
    });

    it('opens in safe mode on ?safe=1', () => {
        window.history.replaceState(null, '', '/dashboard?safe=1');

        const { result } = renderHook(() => useUserWidgetsGate(['a']));

        expect(result.current).toMatchObject({
            mode: 'safe',
            reason: 'requested',
        });
        expect(localStorage.getItem(LOADING_MARKER_KEY)).toBeNull();
    });

    it('does not count leaving the page as a hang', () => {
        const { unmount } = renderHook(() => useUserWidgetsGate(['a']));

        expect(localStorage.getItem(LOADING_MARKER_KEY)).not.toBeNull();

        unmount();

        expect(localStorage.getItem(LOADING_MARKER_KEY)).toBeNull();
    });
});

describe('safe mode without storage', () => {
    it('still runs when localStorage throws', async () => {
        pageProps(true);
        const original = Object.getOwnPropertyDescriptor(
            window,
            'localStorage',
        );
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get() {
                throw new DOMException('blocked', 'SecurityError');
            },
        });

        try {
            const { result } = renderHook(() => useUserWidgetsGate(['a']));

            await waitFor(() => expect(result.current.mode).toBe('run'));
        } finally {
            Object.defineProperty(window, 'localStorage', original!);
        }
    });
});
