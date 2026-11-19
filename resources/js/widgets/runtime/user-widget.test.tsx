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
    router: { visit: vi.fn(), replace: vi.fn() },
}));

// jsdom only dispatches untrusted clicks, which the real gate refuses: let them
// through, keeping what the frame says about navigation.
vi.mock('../sdk/gesture', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../sdk/gesture')>();

    return {
        ...actual,
        beginWidgetGesture: (
            _event: unknown,
            options?: { canNavigate?: boolean; openDialog?: () => void },
        ) => actual.beginWidgetGesture(undefined, options),
    };
});

// The stylesheet is Vite's business; the registry is the real one.
vi.mock('../../../css/widgets.css', () => ({}));

function pageProps() {
    page.props = {
        locale: 'es-ES',
        auth: { user: { id: 'user-1', currency_code: 'EUR' } },
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
        pageProps();
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

    describe('frame', () => {
        const CARD = ['rounded-xl', 'border', 'bg-card'];

        it.each([undefined, 'card' as const])(
            'draws the widget in a card with frame %s',
            async (frame) => {
                const { container } = inApp(
                    <UserWidget
                        js={RENDERS}
                        data={{ total: 100 }}
                        size={{ width: '1/2', height: 'm' }}
                        frame={frame}
                    />,
                );

                await screen.findByText(/1\/2 EUR/);
                const cell = container.querySelector('.wm-widget');

                expect(cell).toHaveAttribute('data-frame', 'card');
                expect(cell).toHaveClass(...CARD);
            },
        );

        it('draws the widget straight on the dashboard with frame none', async () => {
            const { container } = inApp(
                <UserWidget
                    js={RENDERS}
                    data={{ total: 100 }}
                    size={{ width: '1/2', height: 'm' }}
                    frame="none"
                />,
            );

            await screen.findByText(/1\/2 EUR/);
            const cell = container.querySelector('.wm-widget');

            expect(cell).toHaveAttribute('data-frame', 'none');
            expect(cell).toHaveClass('h-full', 'w-full', 'min-w-0');
            expect(cell).not.toHaveClass('border');
            expect(cell).not.toHaveClass('bg-card');
        });

        it('keeps the card while a frameless widget loads', async () => {
            const { container } = inApp(
                <UserWidget
                    js={RENDERS}
                    data={{ total: 100 }}
                    size={{ width: '1/2', height: 'm' }}
                    frame="none"
                />,
            );

            // Read while it loads: the same element turns frameless once ready.
            const loadingClasses = await waitFor(() => {
                const cell = container.querySelector(
                    '.wm-widget[data-status="loading"]',
                );
                expect(cell).not.toBeNull();
                return [...cell!.classList];
            });

            expect(loadingClasses).toEqual(expect.arrayContaining(CARD));
            await screen.findByText(/1\/2 EUR/);
        });

        it.each([
            ['throws', THROWS],
            [
                'does not load',
                'import{x}from"left-pad";export default function W(){}',
            ],
        ])(
            'keeps the card around a frameless widget that %s',
            async (_, js) => {
                vi.spyOn(console, 'error').mockImplementation(() => {});

                const { container } = inApp(
                    <UserWidget
                        js={js}
                        data={{}}
                        size={{ width: '1/2', height: 'm' }}
                        frame="none"
                    />,
                );

                await screen.findByRole('alert');

                expect(container.querySelector('.wm-widget')).toHaveClass(
                    ...CARD,
                );
            },
        );
    });
});

describe('useUserWidgetsGate', () => {
    beforeEach(() => {
        pageProps();
        window.history.replaceState(null, '', '/dashboard');
    });

    afterEach(() => {
        vi.useRealTimers();
        localStorage.clear();
    });

    it('is off with no user widgets', () => {
        expect(renderHook(() => useUserWidgetsGate([])).result.current).toEqual(
            { mode: 'off' },
        );
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
        // What a tab that froze left behind: the user, then that tab's id.
        localStorage.setItem(LOADING_MARKER_KEY, 'user-1:frozen-tab');

        const { result } = renderHook(() => useUserWidgetsGate(['a']));

        expect(result.current).toMatchObject({
            mode: 'safe',
            reason: 'unfinished',
        });

        act(() => (result.current as { exit: () => void }).exit());

        expect(result.current.mode).toBe('run');
    });

    it('ignores the marker another account left in this browser', () => {
        localStorage.setItem(LOADING_MARKER_KEY, 'user-2:frozen-tab');

        const { result } = renderHook(() => useUserWidgetsGate(['a']));

        expect(result.current.mode).toBe('run');
    });

    it('leaves ?safe=1 for good, even over a marker a frozen tab left', async () => {
        const { router } = await import('@inertiajs/react');
        window.history.replaceState(null, '', '/dashboard?safe=1&tab=x');
        localStorage.setItem(LOADING_MARKER_KEY, 'user-1:frozen-tab');

        const { result } = renderHook(() => useUserWidgetsGate(['a']));

        expect(result.current).toMatchObject({ reason: 'requested' });

        act(() => (result.current as { exit: () => void }).exit());

        expect(result.current.mode).toBe('run');
        expect(router.replace).toHaveBeenCalledWith(
            expect.objectContaining({ url: '/dashboard?tab=x' }),
        );
        // The marker now there is this tab's own, for the load it starts.
        expect(localStorage.getItem(LOADING_MARKER_KEY)).not.toBe(
            'user-1:frozen-tab',
        );
    });

    it('does not count a reload or a closed tab as a hang', () => {
        renderHook(() => useUserWidgetsGate(['a']));

        expect(localStorage.getItem(LOADING_MARKER_KEY)).not.toBeNull();

        window.dispatchEvent(new Event('pagehide'));

        expect(localStorage.getItem(LOADING_MARKER_KEY)).toBeNull();
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

    it('reports a throwing button handler through the error boundary', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        vi.stubGlobal('matchMedia', () => ({
            matches: false,
            addEventListener: () => {},
            removeEventListener: () => {},
        }));
        const onError = vi.fn();
        const BUTTON_THROWS = `import{jsx as j}from"react/jsx-runtime";import{Button as B}from"@whisper-money/widget-sdk";export default function W(){return j(B,{onClick:()=>{throw new Error("boom")},children:"Go"})}`;

        inApp(
            <UserWidget
                js={BUTTON_THROWS}
                data={{}}
                size={{ width: '1/2', height: 'm' }}
                onError={onError}
            />,
        );

        (await screen.findByRole('button', { name: 'Go' })).click();

        expect(await screen.findByRole('alert')).toHaveTextContent('boom');
        expect(onError).toHaveBeenCalledWith(
            expect.objectContaining({ phase: 'render', message: 'boom' }),
        );
        vi.unstubAllGlobals();
    });

    it.each([
        [true, 1],
        [false, 0],
    ])(
        'opens a page from a click when canNavigate is %s',
        async (canNavigate, visits) => {
            vi.spyOn(console, 'warn').mockImplementation(() => {});
            vi.stubGlobal('matchMedia', () => ({
                matches: false,
                addEventListener: () => {},
                removeEventListener: () => {},
            }));
            const { router } = await import('@inertiajs/react');
            vi.mocked(router.visit).mockClear();
            const OPENS = `import{jsx as j}from"react/jsx-runtime";import{Button as B,wm}from"@whisper-money/widget-sdk";export default function W(){return j(B,{onClick:()=>wm.open({budget:"0198f0a1-7c3e-7a10-9b2c-1d2e3f4a5b6c"}),children:"Open"})}`;

            inApp(
                <UserWidget
                    js={OPENS}
                    data={{}}
                    size={{ width: '1/2', height: 'm' }}
                    canNavigate={canNavigate}
                />,
            );

            (await screen.findByRole('button', { name: 'Open' })).click();

            expect(router.visit).toHaveBeenCalledTimes(visits);
            vi.unstubAllGlobals();
        },
    );

    it.each([
        [true, 1],
        [false, 0],
    ])(
        "asks the host's dialog from a click when canNavigate is %s",
        async (canNavigate, asks) => {
            vi.spyOn(console, 'warn').mockImplementation(() => {});
            vi.stubGlobal('matchMedia', () => ({
                matches: false,
                addEventListener: () => {},
                removeEventListener: () => {},
            }));
            const openDialog = vi.fn();
            const OPENS = `import{jsx as j}from"react/jsx-runtime";import{Button as B,wm}from"@whisper-money/widget-sdk";export default function W(){return j(B,{onClick:()=>wm.open({newTransaction:{accountId:"0198f0a1-7c3e-7a10-9b2c-1d2e3f4a5b6c"}}),children:"Add"})}`;

            inApp(
                <UserWidget
                    js={OPENS}
                    data={{}}
                    size={{ width: '1/2', height: 'm' }}
                    canNavigate={canNavigate}
                    openDialog={openDialog}
                />,
            );

            (await screen.findByRole('button', { name: 'Add' })).click();

            expect(openDialog).toHaveBeenCalledTimes(asks);
            if (asks > 0) {
                expect(openDialog).toHaveBeenCalledWith({
                    dialog: 'newTransaction',
                    accountId: '0198f0a1-7c3e-7a10-9b2c-1d2e3f4a5b6c',
                });
            }
            vi.unstubAllGlobals();
        },
    );

    it('does not count leaving the page as a hang', () => {
        const { unmount } = renderHook(() => useUserWidgetsGate(['a']));

        expect(localStorage.getItem(LOADING_MARKER_KEY)).not.toBeNull();

        unmount();

        expect(localStorage.getItem(LOADING_MARKER_KEY)).toBeNull();
    });
});

describe('safe mode without storage', () => {
    it('still runs when localStorage throws', async () => {
        pageProps();
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
